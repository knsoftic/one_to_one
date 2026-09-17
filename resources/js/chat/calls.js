import axios from '../bootstrap';
import { errorMessage, formatDuration } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { GroupCall } from './group-call';
import * as T from './templates';

/**
 * One-to-one voice and video calls (WebRTC).
 *
 * Signaling goes through the Laravel API (+ Reverb events when connected):
 *   caller: POST start ─────────────► callee devices ring (call.incoming / push)
 *   callee: POST accept ────────────► caller gets call.updated (ongoing + callee_client)
 *   caller: offer ──► callee: answer ──► ICE candidates both ways ──► media flows peer-to-peer
 *
 * Every tab/phone has its own client id, so only the device that answered takes
 * part; the callee's other devices stop ringing. Polling keeps calls working
 * while the WebSocket is unavailable.
 */

const CLIENT_ID = (window.crypto?.randomUUID?.() ?? `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 14)}`)
    .replace(/[^A-Za-z0-9_-]/g, '');

const SIGNAL_POLL_MS = 1500;
const STATE_POLL_MS = 3000;
const CONNECT_TIMEOUT_MS = 35_000;
const RECONNECT_GRACE_MS = 5000;
const MAX_ICE_RESTARTS = 3;
const END_SCREEN_MS = 1800;
const ERROR_SCREEN_MS = 3500;

const STATS_INTERVAL_MS = 2000;
const LOW_DATA_KEY = 'calls:low-data';
/** Low data mode (K5): what a call may send. */
const LOW_DATA = { videoBitrate: 150_000, videoFramerate: 15, videoScale: 2, audioBitrate: 24_000 };

const END_TEXT = {
    declined: 'Call declined',
    busy: 'On another call',
    missed: 'No answer',
    cancelled: 'Call cancelled',
    failed: "Couldn't connect",
    completed: 'Call ended',
};

/**
 * Connection numbers from a WebRTC stats report (K5).
 *
 * @param {object[]} stats entries of RTCPeerConnection.getStats()
 * @param {{lost?: number, received?: number}} previous counters from the last sample
 * @returns {{rtt: number, jitter: number, loss: number, counters: {lost: number, received: number}}}
 */
export function readCallStats(stats, previous = {}) {
    let rtt = null;
    let jitter = 0;
    let lost = 0;
    let received = 0;
    let remoteLoss = 0;

    for (const entry of stats) {
        if (entry.type === 'candidate-pair' && entry.state === 'succeeded' && (entry.nominated || entry.selected) && typeof entry.currentRoundTripTime === 'number') {
            rtt = entry.currentRoundTripTime;
        }
        // What the other side reports about the media we send.
        if (entry.type === 'remote-inbound-rtp') {
            if (typeof entry.fractionLost === 'number') remoteLoss = Math.max(remoteLoss, entry.fractionLost);
            if (rtt === null && typeof entry.roundTripTime === 'number') rtt = entry.roundTripTime;
        }
        // What we receive (audio is always flowing in a call).
        if (entry.type === 'inbound-rtp' && entry.kind === 'audio') {
            lost += Math.max(0, entry.packetsLost || 0);
            received += entry.packetsReceived || 0;
            jitter = Math.max(jitter, entry.jitter || 0);
        }
    }

    const newLost = Math.max(0, lost - (previous.lost ?? lost));
    const newReceived = Math.max(0, received - (previous.received ?? received));
    const inboundLoss = newLost + newReceived > 0 ? newLost / (newLost + newReceived) : 0;

    return { rtt: rtt ?? 0, jitter, loss: Math.max(inboundLoss, remoteLoss), counters: { lost, received } };
}

/** "good", "weak" or "poor" from round-trip time (s), packet loss (0–1) and jitter (s). */
export function rateConnection({ rtt = 0, loss = 0, jitter = 0 }) {
    if (loss >= 0.1 || rtt >= 0.6 || jitter >= 0.1) return 'poor';
    if (loss >= 0.03 || rtt >= 0.3 || jitter >= 0.05) return 'weak';
    return 'good';
}

/** Avoid flicker: a level shows only after two samples in a row. */
export function smoothQuality(history) {
    const [a, b] = history.slice(-2);
    if (!b) return 'good';
    if (a === 'poor' && b === 'poor') return 'poor';
    if (a !== 'good' && b !== 'good') return 'weak';
    return 'good';
}

/**
 * Keep the floating call window inside the screen (K3).
 *
 * @returns {{x: number, y: number}} top-left corner
 */
export function clampPipPosition(x, y, width, height, viewportWidth, viewportHeight, margin = 8) {
    return {
        x: Math.round(Math.min(Math.max(margin, x), Math.max(margin, viewportWidth - width - margin))),
        y: Math.round(Math.min(Math.max(margin, y), Math.max(margin, viewportHeight - height - margin))),
    };
}

function readLowDataPreference() {
    try {
        return localStorage.getItem(LOW_DATA_KEY) === '1';
    } catch {
        return false;
    }
}

export const callsSupported = () =>
    Boolean(window.RTCPeerConnection && navigator.mediaDevices?.getUserMedia && window.isSecureContext);

/* ---------------------------------------------------------------------- */
/* Tones (generated with Web Audio: no audio files to load)                */
/* ---------------------------------------------------------------------- */

class Tones {
    constructor(getContext) {
        this.getContext = getContext;
        this.timer = null;
        this.nodes = [];
    }

    play(kind) {
        this.stop();
        const ctx = this.getContext();
        if (!ctx) return;
        ctx.resume?.().catch(() => {});

        const patterns = {
            // Short rising melody, repeated.
            ringtone: { every: 2800, gain: 0.14, type: 'sine', notes: [[659, 0, 0.16], [784, 0.18, 0.16], [988, 0.36, 0.32], [784, 0.9, 0.16], [988, 1.08, 0.36]] },
            // Classic ringback heard by the caller.
            ringback: { every: 4000, gain: 0.06, type: 'sine', notes: [[425, 0, 1.2]] },
            end: { every: 0, gain: 0.08, type: 'sine', notes: [[480, 0, 0.16], [360, 0.2, 0.3]] },
        };
        const pattern = patterns[kind];
        if (!pattern) return;

        const run = () => {
            if (ctx.state === 'suspended') return;
            const now = ctx.currentTime;
            for (const [frequency, offset, length] of pattern.notes) {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = pattern.type;
                osc.frequency.value = frequency;
                gain.gain.setValueAtTime(0.0001, now + offset);
                gain.gain.exponentialRampToValueAtTime(pattern.gain, now + offset + 0.03);
                gain.gain.setValueAtTime(pattern.gain, now + offset + Math.max(0.03, length - 0.08));
                gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + length);
                osc.connect(gain).connect(ctx.destination);
                osc.start(now + offset);
                osc.stop(now + offset + length + 0.02);
                this.nodes.push(osc);
                osc.onended = () => (this.nodes = this.nodes.filter((node) => node !== osc));
            }
            if (kind === 'ringtone' && navigator.userActivation?.hasBeenActive) navigator.vibrate?.([400, 250, 400]);
        };

        run();
        if (pattern.every) this.timer = setInterval(run, pattern.every);
    }

    stop() {
        clearInterval(this.timer);
        this.timer = null;
        for (const node of this.nodes) {
            try {
                node.stop();
            } catch {
                /* already stopped */
            }
        }
        this.nodes = [];
        if (navigator.userActivation?.hasBeenActive) navigator.vibrate?.(0);
    }
}

/* ---------------------------------------------------------------------- */
/* Call manager                                                            */
/* ---------------------------------------------------------------------- */

export class CallManager {
    constructor(chat) {
        this.chat = chat;
        this.me = chat.me;
        this.config = chat.config.calls ?? {};
        this.clientId = CLIENT_ID;
        this.enabled = Boolean(this.config.enabled) && chat.api.has('callsStore') && callsSupported();

        /** The call this device takes part in. */
        this.session = null;
        /** A call ringing on this device, not answered yet. */
        this.incoming = null;
        /** Calls that must not ring again (declined, answered elsewhere, ended). */
        this.dismissed = new Set();
        /** Hooks registered by the Android app (speaker, ringtone…). */
        this.native = null;

        this.tones = new Tones(() => chat.notifier?.audio?.() ?? null);

        if (!chat.api.has('callsStore')) return;

        this.renderRoot();
        this.bind();
        // Group calls (K6).
        if (chat.api.has('callRoomShow')) this.group = new GroupCall(this);
        this.updateHeader(chat.activeConversation());

        if (this.enabled) this.restore();
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                          */
    /* ------------------------------------------------------------------ */

    get busy() {
        return Boolean(this.session || this.incoming || this.group?.active);
    }

    /** Called by the Android app to route audio and ring natively. */
    setNativeBridge(bridge) {
        this.native = bridge;
        this.render();
    }

    /** Show/enable the call buttons in the chat header. */
    updateHeader(conversation) {
        const blocked = Boolean(conversation?.blocked_by_me || conversation?.blocked_me);
        document.querySelectorAll('[data-call-button]').forEach((button) => {
            button.hidden = !this.config.enabled || Boolean(conversation?.is_self) || ['broadcast', 'channel'].includes(conversation?.type)
                || Boolean(conversation?.group?.community?.is_announcement);
            button.disabled = blocked || this.busy;
            button.title = blocked ? "You can't call this user" : button.dataset.callLabel;
        });
    }

    async startCall(conversationId, type) {
        conversationId = Number(conversationId);

        if (!this.enabled) {
            toast.error(window.isSecureContext ? 'Calls are not supported in this browser.' : 'Calls need a secure (https) connection.');
            return;
        }
        if (this.busy) {
            toast.info('Finish your current call first.');
            return;
        }

        const conversation = this.chat.conversations.get(conversationId);
        if (conversation?.blocked_by_me || conversation?.blocked_me) {
            toast.error("You can't call this user.");
            return;
        }

        // A group chat rings its people in a group call (Phase 4).
        if (conversation?.type === 'group') {
            if (!conversation.group?.is_member) {
                toast.error("You're no longer a member of this group.");
                return;
            }
            this.group?.startForGroup(conversation, type);
            return;
        }

        const peer = this.chat.decorate(this.chat.participantOf(conversation) ?? {});
        const session = this.createSession({
            call: { id: null, conversation_id: conversationId, type, caller_id: this.me.id },
            role: 'caller',
            peer,
        });
        this.session = session;
        this.openScreen();
        this.setStatus('outgoing', type === 'video' ? 'Starting camera…' : 'Starting…');

        try {
            session.localStream = await this.getMedia(type, session);
        } catch (error) {
            this.teardown(session, this.mediaError(error, type), { error: true });
            return;
        }
        if (session.status === 'ended') return this.stopStream(session.localStream);
        this.attachLocal();

        let data;
        try {
            data = await this.post('callsStore', conversationId, { type, client_id: this.clientId });
        } catch (error) {
            this.teardown(session, errorMessage(error, "Couldn't start the call."), { error: true });
            return;
        }

        this.applyCallData(session, data);

        if (session.status === 'ended') {
            // Hung up while the call was being created.
            if (data.call.status !== 'ended') this.post('callEnd', data.call.id, { reason: 'hangup' }).catch(() => {});
            return;
        }
        if (data.call.status === 'ended') {
            this.finish(data.call);
            return;
        }

        this.setStatus('outgoing', 'Calling…');
        this.emit('outgoing');
        this.startHeartbeat(session);
        this.startStatePolling(session);
        session.timers.ring = setTimeout(() => this.hangUp('no_answer'), (this.config.ringTimeoutSeconds || 45) * 1000);
    }

    async accept() {
        const incoming = this.incoming;
        if (!incoming || incoming.accepting) return;
        incoming.accepting = true;

        this.clearIncomingTimers(incoming);
        this.tones.stop();
        this.closeBrowserNotification(incoming);
        this.incoming = null;
        this.emit('incoming-answered', incoming.call);

        const session = this.createSession({ call: incoming.call, role: 'callee', peer: incoming.peer, iceServers: incoming.iceServers });
        this.session = session;
        this.openScreen();
        this.setStatus('connecting', 'Connecting…');

        try {
            session.localStream = await this.getMedia(session.type, session);
        } catch (error) {
            this.post('callDecline', session.call.id).catch(() => {});
            this.teardown(session, this.mediaError(error, session.type), { error: true });
            return;
        }
        if (session.status === 'ended') return this.stopStream(session.localStream);

        this.attachLocal();
        // An invite to a group call (K6) connects to everyone in it after answering.
        const groupCall = Boolean(session.call.call_room_id && this.group);
        // Ready before accepting: the caller's offer can arrive right after.
        if (!groupCall) this.createPeer(session);

        try {
            const data = await this.post('callAccept', session.call.id, { client_id: this.clientId });
            this.applyCallData(session, data);
        } catch (error) {
            this.teardown(session, errorMessage(error, "Couldn't answer the call."), { error: true });
            return;
        }

        if (groupCall) {
            this.group.joinFromSession(session, session.iceServers);
            return;
        }

        // Fresh TURN credentials from the answer; keep the rest of the configuration unchanged.
        if (session.iceServers?.length) {
            try {
                session.pc.setConfiguration({ ...session.pc.getConfiguration(), iceServers: session.iceServers });
            } catch {
                /* already negotiating: the servers given when ringing are still valid */
            }
        }

        this.emit('active');
        this.startHeartbeat(session);
        this.startSignalPolling(session);
        this.startStatePolling(session);
        this.startConnectTimer(session);
        this.fetchSignals();
    }

    async decline() {
        const incoming = this.incoming;
        if (!incoming) return;
        this.post('callDecline', incoming.call.id).catch(() => {});
        this.dismissIncoming();
    }

    hangUp(reason = 'hangup') {
        if (this.group?.active && !this.session) {
            this.group.leave();
            return;
        }
        const session = this.session;
        if (!session || session.status === 'ended') return;

        if (session.call.id) {
            this.post('callEnd', session.call.id, { reason }).catch(() => {});
        }

        const text = reason === 'no_answer' ? 'No answer' : reason === 'failed' ? "Couldn't connect the call" : 'Call ended';
        this.teardown(session, text, { error: reason === 'failed' });
    }

    /**
     * Open an incoming call from a link or the phone's incoming-call screen.
     *
     * @returns {Promise<boolean>} whether the call is ringing (or was answered) here
     */
    async resumeFromLink(callId, answer = false) {
        callId = Number(callId);
        if (!this.enabled || !callId) return false;
        if (this.session?.call.id === callId) {
            this.showScreen();
            return true;
        }

        try {
            const data = await this.get('callShow', callId);
            const call = data.call;

            if (call.status !== 'ringing' || Number(call.callee_id) !== Number(this.me.id)) {
                if (call.status === 'ended' && answer) toast.info('The call has ended.');
                return false;
            }

            this.dismissed.delete(callId);
            await this.onIncoming(call, data.ice_servers, { silent: answer });
            if (answer && this.incoming?.call.id === callId) await this.accept();
            return true;
        } catch {
            return false;
        }
    }

    /** The phone received a call push while the app is open. */
    checkIncoming(callId) {
        this.resumeFromLink(callId, false);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Realtime events & polling sync                                      */
    /* ------------------------------------------------------------------ */

    async onIncoming(call, iceServers = null, { silent = false } = {}) {
        if (!this.enabled || call.status !== 'ringing' || Number(call.callee_id) !== Number(this.me.id)) return;
        if (this.dismissed.has(call.id) || this.incoming?.call.id === call.id || this.session?.call.id === call.id) return;
        if (this.busy) return;

        const timeoutMs = (this.config.ringTimeoutSeconds || 45) * 1000;
        const elapsed = this.serverNow(call) - Date.parse(call.created_at);
        if (elapsed > timeoutMs) return;

        const incoming = {
            call,
            peer: this.chat.decorate(call.caller ?? this.chat.users.get(Number(call.caller_id)) ?? {}),
            iceServers: iceServers ?? [],
            timers: {},
        };
        this.incoming = incoming;
        this.chat.rememberUser?.(call.caller ?? {});

        this.openScreen();
        this.render();
        if (!silent) {
            // Answered from the phone's call screen: connect straight away without ringing again.
            if (!this.native?.ringtone) this.tones.play('ringtone');
            this.showBrowserNotification(incoming);
            this.emit('incoming', call);
        }
        this.updateHeader(this.chat.activeConversation());

        incoming.timers.timeout = setTimeout(() => {
            if (this.incoming === incoming) this.dismissIncoming();
        }, Math.max(5000, timeoutMs - elapsed + 3000));

        this.post('callRinging', call.id).catch(() => {});

        // Fresh state + ICE servers; keep checking in case the caller hangs up.
        const refresh = async () => {
            try {
                const data = await this.get('callShow', call.id);
                if (this.incoming !== incoming) return;
                if (data.ice_servers?.length) incoming.iceServers = data.ice_servers;
                if (data.call.status !== 'ringing') this.onCallUpdated(data.call);
            } catch (error) {
                if (this.incoming === incoming && error?.response?.status === 404) this.dismissIncoming();
            }
        };
        if (!iceServers) refresh();
        incoming.timers.poll = setInterval(refresh, STATE_POLL_MS);
    }

    onCallUpdated(call) {
        if (!call?.id) return;

        if (this.incoming?.call.id === call.id) {
            if (call.status === 'ongoing' && call.callee_client !== this.clientId) {
                this.dismissIncoming('Answered on another device');
            } else if (call.status === 'ended') {
                this.dismissIncoming();
            }
            return;
        }

        const session = this.session;
        if (!session || session.call.id !== call.id || session.status === 'ended') return;

        const wasGroupCall = Boolean(session.call.call_room_id);
        session.call = { ...session.call, ...call, caller: call.caller ?? session.call.caller, callee: call.callee ?? session.call.callee };
        if (call.server_time) session.clockOffset = Date.parse(call.server_time) - Date.now();

        if (call.status === 'ended') {
            this.finish(call);
            return;
        }

        // "Add person" turned this call into a group call (K6): follow it even when the
        // live event was missed and this update came from polling.
        if (call.call_room_id && !wasGroupCall && this.group && !this.group.active && !session.movingToGroup && session.status !== 'ended') {
            session.movingToGroup = true;
            this.group.joinFromSession(session, session.iceServers);
            return;
        }

        if (call.type === 'video' && session.type !== 'video' && session.status === 'connected') {
            this.becomeVideo(session);
        }

        if (session.role === 'caller') {
            if (call.status === 'ringing' && call.ringing_at && session.status === 'outgoing' && !session.ringing) {
                session.ringing = true;
                this.setStatus('outgoing', 'Ringing…');
                this.tones.play('ringback');
            }
            if (call.status === 'ongoing' && !session.negotiating) {
                this.beginNegotiation(session);
            }
        }
    }

    onSignal(signal) {
        if (signal.call_room_id) {
            this.group?.onSignal(signal);
            return;
        }
        const session = this.session;
        if (!session || Number(session.call.id) !== Number(signal.call_id)) return;
        if (signal.to_client && signal.to_client !== this.clientId) return;

        if (signal.payload == null) {
            this.fetchSignals();
            return;
        }
        this.queueSignal(session, signal);
    }

    /** Active calls from the polling sync. */
    syncCalls(calls = []) {
        if (!this.enabled) return;

        for (const call of calls) {
            if (call.status === 'ringing' && Number(call.callee_id) === Number(this.me.id)) this.onIncoming(call);
            else this.onCallUpdated(call);
        }

        const activeIds = new Set(calls.map((call) => call.id));
        if (this.incoming && !activeIds.has(this.incoming.call.id)) this.refreshState(this.incoming.call.id);
        if (this.session?.call.id && this.session.status !== 'ended' && !activeIds.has(this.session.call.id)) {
            this.refreshState(this.session.call.id);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Session & WebRTC                                                    */
    /* ------------------------------------------------------------------ */

    createSession({ call, role, peer, iceServers = [] }) {
        return {
            call,
            role,
            peer,
            type: call.type,
            iceServers,
            status: 'outgoing',
            statusText: '',
            localStream: null,
            remoteStream: null,
            pc: null,
            pendingCandidates: [],
            handled: new Set(),
            cursor: 0,
            queue: Promise.resolve(),
            sdpInFlight: Promise.resolve(),
            negotiating: false,
            connectedOnce: false,
            connectedAt: null,
            restarts: 0,
            muted: false,
            cameraOff: false,
            noCamera: false,
            noMicrophone: false,
            facing: 'user',
            canFlip: false,
            speaker: call.type === 'video',
            remoteMuted: false,
            remoteCameraOff: false,
            remoteVideoLive: false,
            clockOffset: 0,
            minimized: false,
            quality: 'good',
            qualityHistory: [],
            lowData: readLowDataPreference(),
            remoteLowData: false,
            timers: {},
            intervals: {},
        };
    }

    applyCallData(session, data) {
        session.call = { ...session.call, ...data.call };
        session.type = data.call.type ?? session.type;
        if (data.ice_servers?.length) session.iceServers = data.ice_servers;
        if (data.call.server_time) session.clockOffset = Date.parse(data.call.server_time) - Date.now();
        if (data.call.callee && session.role === 'caller') session.peer = this.chat.decorate(data.call.callee);
    }

    async beginNegotiation(session) {
        session.negotiating = true;
        clearTimeout(session.timers.ring);
        this.tones.stop();
        this.setStatus('connecting', 'Connecting…');
        this.emit('active');

        this.createPeer(session);
        this.startSignalPolling(session);
        this.startConnectTimer(session);

        try {
            await this.sendOffer(session);
        } catch (error) {
            console.warn('Could not create the call offer', error);
            this.hangUp('failed');
        }
    }

    createPeer(session) {
        const pc = new RTCPeerConnection({ iceServers: session.iceServers ?? [], iceCandidatePoolSize: 2 });
        session.pc = pc;

        session.localStream?.getTracks().forEach((track) => pc.addTrack(track, session.localStream));
        // Still receive what this device can't send (no microphone).
        if (!session.localStream?.getAudioTracks().length) {
            pc.addTransceiver('audio', { direction: 'recvonly' });
        }
        // Every call has a video line, sending nothing until a camera is on, so either
        // side can switch a voice call to video without renegotiating (K2).
        if (session.role === 'caller' && !session.localStream?.getVideoTracks().length) {
            pc.addTransceiver('video', { direction: 'sendrecv' });
        }

        pc.onicecandidate = ({ candidate }) => {
            if (candidate && session === this.session) this.sendSignal(session, 'candidate', candidate.toJSON());
        };

        pc.ontrack = (event) => {
            const stream = event.streams?.[0];
            if (stream) {
                session.remoteStream = stream;
            } else {
                session.remoteStream ??= new MediaStream();
                session.remoteStream.addTrack(event.track);
            }

            if (event.track.kind === 'video') {
                const update = () => {
                    session.remoteVideoLive = !event.track.muted && event.track.readyState === 'live';
                    // The other person turned their camera on during a voice call (K2).
                    if (session.remoteVideoLive && session.type !== 'video' && session === this.session) {
                        session.remoteTurnedOnVideo = true;
                        this.becomeVideo(session);
                    }
                    this.render();
                };
                event.track.onunmute = update;
                event.track.onmute = update;
                event.track.onended = update;
                update();
            }
            this.attachRemote();
        };

        const onState = () => {
            const state = pc.connectionState ?? { checking: 'connecting', completed: 'connected' }[pc.iceConnectionState] ?? pc.iceConnectionState;
            this.onConnectionState(session, state);
        };
        pc.onconnectionstatechange = onState;
        if (!('connectionState' in pc)) pc.oniceconnectionstatechange = onState;
    }

    async sendOffer(session, { iceRestart = false } = {}) {
        const pc = session.pc;
        if (!pc || pc.signalingState === 'closed') return;
        const offer = await pc.createOffer({ iceRestart });
        await pc.setLocalDescription(offer);
        await this.sendSignal(session, 'offer', { type: offer.type, sdp: offer.sdp });
    }

    onConnectionState(session, state) {
        if (session !== this.session || session.status === 'ended') return;

        switch (state) {
            case 'connected':
                clearTimeout(session.timers.reconnect);
                clearTimeout(session.timers.connect);
                session.restarts = 0;
                this.stopSignalPolling(session);
                this.stopStatePolling(session);
                if (!session.connectedOnce) {
                    session.connectedOnce = true;
                    session.connectedAt = Date.now();
                    this.emit('connected');
                    this.sendMediaState(session);
                    this.detectCameras(session);
                    this.startQualityMonitor(session);
                    if (session.lowData) this.applyDataLimits(session);
                }
                this.setStatus('connected');
                this.startDurationTimer(session);
                break;

            case 'disconnected':
                this.setStatus('reconnecting', 'Reconnecting…');
                this.startSignalPolling(session);
                clearTimeout(session.timers.reconnect);
                session.timers.reconnect = setTimeout(() => this.restartIce(session), RECONNECT_GRACE_MS);
                break;

            case 'failed':
                this.restartIce(session);
                break;

            default:
                break;
        }
    }

    restartIce(session) {
        if (session !== this.session || session.status === 'ended' || !session.pc) return;
        if (session.pc.connectionState === 'connected') return;

        if (session.restarts >= MAX_ICE_RESTARTS) {
            this.hangUp('failed');
            return;
        }
        session.restarts++;
        this.setStatus('reconnecting', 'Reconnecting…');
        this.startSignalPolling(session);

        // Only the caller creates offers; the callee answers the new one.
        if (session.role === 'caller') {
            this.sendOffer(session, { iceRestart: true }).catch(() => {});
        }

        clearTimeout(session.timers.connect);
        session.timers.connect = setTimeout(() => {
            if (session === this.session && session.status !== 'connected') this.hangUp('failed');
        }, CONNECT_TIMEOUT_MS);
    }

    startConnectTimer(session) {
        clearTimeout(session.timers.connect);
        session.timers.connect = setTimeout(() => {
            if (session === this.session && !session.connectedOnce) this.hangUp('failed');
        }, CONNECT_TIMEOUT_MS);
    }

    /* ------------------------------------------------------------------ */
    /* Signaling                                                           */
    /* ------------------------------------------------------------------ */

    async sendSignal(session, type, payload) {
        if (!session.call.id || session.status === 'ended') return;

        const toClient = session.role === 'caller' ? session.call.callee_client : session.call.caller_client;
        const body = { client_id: this.clientId, to_client: toClient || null, type, payload: JSON.stringify(payload) };
        const send = () => this.post('callSignalsStore', session.call.id, body);

        try {
            if (type === 'offer' || type === 'answer') {
                // Candidates wait for the description they belong to (keeps their order on the other side).
                session.sdpInFlight = send();
                await session.sdpInFlight;
            } else {
                await session.sdpInFlight.catch(() => {});
                await send();
            }
        } catch (error) {
            if (error?.response?.status === 409 || error?.response?.status === 404) this.refreshState(session.call.id);
        }
    }

    async fetchSignals() {
        const session = this.session;
        if (!session?.call.id || session.status === 'ended') return;
        if (session.fetching) {
            session.refetch = true;
            return;
        }
        session.fetching = true;

        try {
            const data = await this.get('callSignals', session.call.id, { client_id: this.clientId, after: session.cursor });
            if (session !== this.session) return;
            for (const signal of data.data ?? []) {
                session.cursor = Math.max(session.cursor, Number(signal.id));
                this.queueSignal(session, signal);
            }
            if (data.status === 'ended') this.refreshState(session.call.id);
        } catch (error) {
            if (error?.response?.status === 404) this.refreshState(session.call.id);
        } finally {
            session.fetching = false;
            if (session.refetch) {
                session.refetch = false;
                this.fetchSignals();
            }
        }
    }

    queueSignal(session, signal) {
        session.queue = session.queue.then(() => this.processSignal(session, signal)).catch((error) => {
            console.warn('Call signal failed', error);
        });
    }

    async processSignal(session, signal) {
        if (session !== this.session || session.status === 'ended' || session.handled.has(signal.id)) return;
        session.handled.add(signal.id);

        let data;
        try {
            data = JSON.parse(signal.payload);
        } catch {
            return;
        }

        const pc = session.pc;

        switch (signal.type) {
            case 'offer': {
                if (session.role !== 'callee' || !pc) return;
                await pc.setRemoteDescription(data);
                this.openVideoLine(session);
                await this.flushCandidates(session);
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await this.sendSignal(session, 'answer', { type: answer.type, sdp: answer.sdp });
                break;
            }
            case 'answer':
                if (!pc || pc.signalingState !== 'have-local-offer') return;
                await pc.setRemoteDescription(data);
                await this.flushCandidates(session);
                break;

            case 'candidate':
                if (!pc || !pc.remoteDescription) {
                    session.pendingCandidates.push(data);
                    return;
                }
                await pc.addIceCandidate(data).catch(() => {});
                break;

            case 'media':
                session.remoteMuted = Boolean(data.muted);
                session.remoteCameraOff = Boolean(data.cameraOff);
                session.remoteScreen = Boolean(data.screen);
                if (Boolean(data.lowData) !== session.remoteLowData) {
                    session.remoteLowData = Boolean(data.lowData);
                    this.applyDataLimits(session);
                }
                if (data.video && session.type !== 'video') {
                    session.remoteTurnedOnVideo = true;
                    this.becomeVideo(session);
                }
                this.render();
                break;

            default:
                break;
        }
    }

    async flushCandidates(session) {
        const candidates = session.pendingCandidates.splice(0);
        for (const candidate of candidates) {
            await session.pc.addIceCandidate(candidate).catch(() => {});
        }
    }

    sendMediaState(session) {
        this.sendSignal(session, 'media', {
            muted: session.muted,
            cameraOff: session.cameraOff,
            video: session.type === 'video',
            screen: Boolean(session.screenSharing),
            lowData: Boolean(session.lowData),
        });
    }

    /* ------------------------------------------------------------------ */
    /* Call quality & low data mode (K5)                                   */
    /* ------------------------------------------------------------------ */

    startQualityMonitor(session) {
        if (session.intervals.stats || !session.pc?.getStats) return;
        session.intervals.stats = setInterval(() => this.sampleQuality(session), STATS_INTERVAL_MS);
    }

    async sampleQuality(session) {
        if (session !== this.session || session.status === 'ended' || !session.pc?.getStats) return;
        try {
            const report = await session.pc.getStats();
            const sample = readCallStats([...report.values()], session.statsCounters);
            session.statsCounters = sample.counters;
            session.qualityHistory = [...session.qualityHistory, rateConnection(sample)].slice(-3);
            const quality = smoothQuality(session.qualityHistory);
            if (quality !== session.quality) {
                session.quality = quality;
                this.render();
            }
        } catch {
            /* stats are best effort */
        }
    }

    /** Use less data: lower video resolution, frame rate and bitrate, and audio bitrate. */
    async toggleLowData() {
        const session = this.session;
        if (!session || session.status === 'ended') return;
        session.lowData = !session.lowData;
        try {
            localStorage.setItem(LOW_DATA_KEY, session.lowData ? '1' : '0');
        } catch {
            /* storage unavailable */
        }
        await this.applyDataLimits(session);
        this.sendMediaState(session);
        toast.info(session.lowData ? 'Low data mode on: video quality is lowered to use less data.' : 'Low data mode off.', { timeout: 2500 });
        this.render();
    }

    /** Low data mode applies when either person turned it on. */
    async applyDataLimits(session) {
        const low = Boolean(session.lowData || session.remoteLowData);
        for (const transceiver of session.pc?.getTransceivers?.() ?? []) {
            const kind = transceiver.receiver?.track?.kind;
            const sender = transceiver.sender;
            if (!sender?.getParameters || !kind) continue;
            try {
                const params = sender.getParameters();
                if (!params.encodings?.length) continue;
                const encoding = params.encodings[0];
                if (kind === 'video') {
                    if (low) {
                        Object.assign(encoding, { maxBitrate: LOW_DATA.videoBitrate, maxFramerate: LOW_DATA.videoFramerate, scaleResolutionDownBy: LOW_DATA.videoScale });
                    } else {
                        delete encoding.maxBitrate;
                        delete encoding.maxFramerate;
                        encoding.scaleResolutionDownBy = 1;
                    }
                } else if (low) {
                    encoding.maxBitrate = LOW_DATA.audioBitrate;
                } else {
                    delete encoding.maxBitrate;
                }
                await sender.setParameters(params);
            } catch {
                /* this browser can't change it during the call */
            }
        }
    }

    renderQuality(session, view) {
        const pill = this.el.quality;
        const show = Boolean(session && view === 'connected' && (session.lowData || session.quality !== 'good'));
        pill.hidden = !show;
        if (!show) return;

        const quality = session.quality;
        pill.dataset.quality = quality;
        pill.classList.toggle('is-low-data', Boolean(session.lowData));
        const label = { poor: 'Poor connection', weak: 'Weak connection' }[quality];
        pill.innerHTML = session.lowData
            ? `${icon('gauge')}<span>Low data mode${label ? ` · ${label}` : ''}</span><strong>Turn off</strong>`
            : `${icon(quality === 'poor' ? 'signal-low' : 'signal-medium')}<span>${label}</span><strong>Use less data</strong>`;
        pill.setAttribute('aria-label', session.lowData ? 'Low data mode is on. Turn it off' : `${label}. Use less data`);
    }

    /** Callee: answer the offer's video line as send-and-receive, ready for a camera later (K2). */
    openVideoLine(session) {
        session.pc?.getTransceivers?.().forEach((transceiver) => {
            if (transceiver.receiver?.track?.kind === 'video' && !transceiver.stopped && ['recvonly', 'inactive'].includes(transceiver.direction)) {
                transceiver.direction = 'sendrecv';
            }
        });
    }

    videoSender(session) {
        return session.pc?.getTransceivers?.().find((transceiver) => transceiver.receiver?.track?.kind === 'video' && !transceiver.stopped)?.sender ?? null;
    }

    hasLiveCamera(session) {
        return Boolean(session?.localStream?.getVideoTracks().some((track) => track.readyState === 'live'));
    }

    /* ------------------------------------------------------------------ */
    /* Timers                                                              */
    /* ------------------------------------------------------------------ */

    startHeartbeat(session) {
        clearInterval(session.intervals.heartbeat);
        session.intervals.heartbeat = setInterval(async () => {
            if (session !== this.session || !session.call.id) return;
            try {
                const data = await this.post('callHeartbeat', session.call.id);
                if (data.status === 'ended') this.refreshState(session.call.id);
            } catch (error) {
                if (error?.response?.status === 404) this.refreshState(session.call.id);
            }
        }, (this.config.heartbeatSeconds || 20) * 1000);
    }

    startStatePolling(session) {
        clearInterval(session.intervals.state);
        session.intervals.state = setInterval(() => {
            if (session === this.session && session.call.id) this.refreshState(session.call.id);
        }, STATE_POLL_MS);
    }

    stopStatePolling(session) {
        clearInterval(session.intervals.state);
    }

    startSignalPolling(session) {
        if (session.intervals.signals) return;
        session.intervals.signals = setInterval(() => {
            if (session === this.session) this.fetchSignals();
        }, SIGNAL_POLL_MS);
    }

    stopSignalPolling(session) {
        clearInterval(session.intervals.signals);
        session.intervals.signals = null;
    }

    startDurationTimer(session) {
        if (session.intervals.duration) return;
        session.intervals.duration = setInterval(() => this.renderDuration(), 1000);
        this.renderDuration();
    }

    async refreshState(callId) {
        try {
            const data = await this.get('callShow', callId);
            this.onCallUpdated(data.call);
        } catch (error) {
            if (error?.response?.status === 404) {
                if (this.incoming?.call.id === callId) this.dismissIncoming();
                if (this.session?.call.id === callId) this.teardown(this.session, 'Call ended');
            }
        }
    }

    serverNow(call) {
        const offset = call?.server_time ? Date.parse(call.server_time) - Date.now() : 0;
        return Date.now() + offset;
    }

    elapsedSeconds(session) {
        const answeredAt = Date.parse(session.call.answered_at ?? '') || session.connectedAt;
        if (!answeredAt) return 0;
        return Math.max(0, Math.floor((Date.now() + session.clockOffset - answeredAt) / 1000));
    }

    /* ------------------------------------------------------------------ */
    /* Ending                                                              */
    /* ------------------------------------------------------------------ */

    finish(call) {
        const session = this.session;
        if (!session || session.status === 'ended') return;

        let text = END_TEXT[call.end_reason] ?? 'Call ended';
        if (call.end_reason === 'declined' && session.role === 'callee') text = 'Call ended';
        this.teardown(session, text, { error: ['busy', 'failed', 'declined', 'missed'].includes(call.end_reason) && !session.connectedOnce });
    }

    teardown(session, text, { error = false } = {}) {
        if (session.status === 'ended') return;

        const duration = session.connectedOnce ? this.elapsedSeconds(session) : 0;
        session.status = 'ended';
        session.statusText = duration ? `${text} · ${formatDuration(duration)}` : text;

        Object.values(session.timers).forEach(clearTimeout);
        Object.values(session.intervals).forEach(clearInterval);
        this.tones.stop();
        if (session.negotiating || session.role === 'callee') this.tones.play('end');

        try {
            session.pc?.close();
        } catch {
            /* already closed */
        }
        this.stopStream(session.localStream);
        session.screenTrack?.stop();
        session.screenSharing = false;
        if (document.pictureInPictureElement) document.exitPictureInPicture?.().catch(() => {});
        this.setAutoPictureInPicture(false);
        this.el.pipVideo.srcObject = null;
        this.el.remoteVideo.srcObject = null;
        this.el.remoteAudio.srcObject = null;
        this.el.localVideo.srcObject = null;

        if (session.call.id) this.dismissed.add(session.call.id);
        this.render();
        this.emit('ended');

        setTimeout(() => {
            if (this.session !== session) return;
            this.session = null;
            this.hideScreen();
            this.updateHeader(this.chat.activeConversation());
        }, error ? ERROR_SCREEN_MS : END_SCREEN_MS);
    }

    dismissIncoming(message = null) {
        const incoming = this.incoming;
        if (!incoming) return;

        this.clearIncomingTimers(incoming);
        this.tones.stop();
        this.closeBrowserNotification(incoming);
        this.dismissed.add(incoming.call.id);
        this.incoming = null;
        this.emit('incoming-dismissed', incoming.call);

        if (!this.session) this.hideScreen();
        this.updateHeader(this.chat.activeConversation());
        if (message) toast.info(message, { timeout: 2500 });
    }

    clearIncomingTimers(incoming) {
        Object.values(incoming.timers).forEach((timer) => {
            clearTimeout(timer);
            clearInterval(timer);
        });
    }

    stopStream(stream) {
        stream?.getTracks().forEach((track) => track.stop());
    }

    /* ------------------------------------------------------------------ */
    /* Media                                                               */
    /* ------------------------------------------------------------------ */

    async getMedia(type, session) {
        const audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
        const video = type === 'video' ? this.videoConstraints('user') : false;
        const denied = (error) => ['NotAllowedError', 'SecurityError', 'AbortError'].includes(error?.name);

        try {
            return await navigator.mediaDevices.getUserMedia({ audio, video });
        } catch (error) {
            if (denied(error)) throw error;
        }

        // A device is missing or busy (e.g. a desktop PC without a microphone or webcam):
        // take whatever works, so the call still connects and you can see and hear the other side.
        const stream = new MediaStream();

        try {
            (await navigator.mediaDevices.getUserMedia({ audio })).getTracks().forEach((track) => stream.addTrack(track));
        } catch (error) {
            if (denied(error)) throw error;
            session.noMicrophone = true;
            session.muted = true;
        }

        if (type === 'video') {
            try {
                (await navigator.mediaDevices.getUserMedia({ video })).getTracks().forEach((track) => stream.addTrack(track));
            } catch (error) {
                if (denied(error)) throw error;
                session.noCamera = true;
                session.cameraOff = true;
            }
        }

        if (session.noMicrophone) {
            toast.warning('No microphone found. The other person won’t hear you.', { timeout: 6000 });
        }
        return stream;
    }

    videoConstraints(facing) {
        return { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30, max: 30 } };
    }

    mediaError(error, type) {
        const device = type === 'video' ? 'camera and microphone' : 'microphone';
        switch (error?.name) {
            case 'NotAllowedError':
            case 'SecurityError':
                return `Allow ${device} access to make calls.`;
            case 'NotFoundError':
            case 'OverconstrainedError':
                return 'No microphone found.';
            case 'NotReadableError':
                return `Your ${device} is being used by another app.`;
            default:
                return `Couldn't use your ${device}.`;
        }
    }

    async detectCameras(session) {
        if (session.type !== 'video' || session.noCamera) return;
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            session.canFlip = devices.filter((device) => device.kind === 'videoinput').length > 1;
            this.render();
        } catch {
            /* not available */
        }
    }

    toggleMute() {
        const session = this.session;
        if (!session || session.status === 'ended') return;
        if (session.noMicrophone) {
            toast.info('No microphone found on this device.');
            return;
        }
        session.muted = !session.muted;
        session.localStream?.getAudioTracks().forEach((track) => (track.enabled = !session.muted));
        this.sendMediaState(session);
        this.render();
    }

    toggleCamera() {
        const session = this.session;
        if (!session || session.status === 'ended') return;
        if (!this.hasLiveCamera(session)) {
            this.turnOnCamera();
            return;
        }
        session.cameraOff = !session.cameraOff;
        session.localStream?.getVideoTracks().forEach((track) => (track.enabled = !session.cameraOff));
        this.sendMediaState(session);
        this.render();
    }

    async flipCamera() {
        const session = this.session;
        const current = session?.localStream?.getVideoTracks()[0];
        if (!session || !current || session.flipping) return;
        session.flipping = true;

        const facing = session.facing === 'user' ? 'environment' : 'user';
        const replace = async (wanted) => {
            const stream = await navigator.mediaDevices.getUserMedia({ video: this.videoConstraints(wanted) });
            const track = stream.getVideoTracks()[0];
            track.enabled = !session.cameraOff;
            const sender = session.pc?.getSenders().find((item) => item.track?.kind === 'video' || item === session.videoSender);
            await sender?.replaceTrack(track);
            session.videoSender = sender;
            session.localStream.getVideoTracks().forEach((old) => session.localStream.removeTrack(old));
            session.localStream.addTrack(track);
            session.facing = wanted;
        };

        // Many phones can't open two cameras at once: release the current one first.
        current.stop();
        try {
            await replace(facing);
        } catch {
            try {
                await replace(session.facing);
            } catch {
                toast.error("Couldn't switch the camera.");
            }
        } finally {
            session.flipping = false;
            this.attachLocal();
            this.render();
        }
    }

    /**
     * Start this device's camera during the call: a voice call becomes a video call (K2).
     */
    async turnOnCamera() {
        const session = this.session;
        if (!session || session.status !== 'connected' || session.switchingVideo) {
            if (session && session.status !== 'connected') toast.info('Wait until the call connects.');
            return;
        }
        if (session.screenSharing) {
            toast.info('Stop sharing your screen first.');
            return;
        }
        const sender = this.videoSender(session);
        if (!sender) {
            toast.info('Video is not available in this call.');
            return;
        }

        session.switchingVideo = true;
        this.render();
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: this.videoConstraints('user') });
            const track = stream.getVideoTracks()[0];
            if (session !== this.session || session.status === 'ended') {
                track.stop();
                return;
            }
            await sender.replaceTrack(track);
            session.localStream ??= new MediaStream();
            session.localStream.getVideoTracks().forEach((old) => {
                old.stop();
                session.localStream.removeTrack(old);
            });
            session.localStream.addTrack(track);
            Object.assign(session, { facing: 'user', cameraOff: false, noCamera: false });

            this.becomeVideo(session);
            this.sendMediaState(session);
            this.detectCameras(session);
            if (session.call.id) this.post('callVideo', session.call.id).catch(() => {});
        } catch (error) {
            toast.error(['NotAllowedError', 'SecurityError'].includes(error?.name) ? 'Allow camera access to turn on video.' : this.mediaError(error, 'video'));
        } finally {
            session.switchingVideo = false;
            this.render();
        }
    }

    get screenShareSupported() {
        return Boolean(navigator.mediaDevices?.getDisplayMedia) && !this.native;
    }

    /**
     * Show your screen (or a window / tab) instead of the camera (K4). Uses the call's
     * video line, so voice and video calls both work without reconnecting.
     */
    async toggleScreenShare() {
        const session = this.session;
        if (!session || session.status !== 'connected') return;
        if (session.screenSharing) {
            await this.stopScreenShare(session);
            return;
        }
        if (!this.screenShareSupported) {
            toast.info('Screen sharing works in Chrome, Edge, Firefox and Safari on a computer.');
            return;
        }
        const sender = this.videoSender(session);
        if (!sender || session.startingShare) return;

        session.startingShare = true;
        try {
            const stream = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: { ideal: 15, max: 30 } }, audio: false });
            const track = stream.getVideoTracks()[0];
            if (!track || session !== this.session || session.status === 'ended') {
                stream.getTracks().forEach((t) => t.stop());
                return;
            }
            // Text and slides stay sharp; the browser lowers the frame rate instead of the resolution.
            if ('contentHint' in track) track.contentHint = 'detail';
            await sender.replaceTrack(track);

            session.screenTrack = track;
            session.screenSharing = true;
            track.onended = () => this.stopScreenShare(session);
            this.showScreenPreview(session);
            this.sendMediaState(session);
        } catch (error) {
            if (error?.name !== 'NotAllowedError' && error?.name !== 'AbortError') {
                toast.error("Couldn't share your screen.");
            }
        } finally {
            session.startingShare = false;
            this.render();
        }
    }

    /** Back to the camera (if it was on) or to no video. */
    async stopScreenShare(session = this.session) {
        if (!session?.screenSharing) return;
        const track = session.screenTrack;
        session.screenSharing = false;
        session.screenTrack = null;
        if (track) {
            track.onended = null;
            track.stop();
        }

        if (session.status !== 'ended') {
            const camera = session.localStream?.getVideoTracks().find((t) => t.readyState === 'live') ?? null;
            try {
                await this.videoSender(session)?.replaceTrack(camera);
            } catch {
                /* the call is closing */
            }
            this.attachLocal();
            this.sendMediaState(session);
        }
        this.render();
    }

    /** Your own screen in the small preview while sharing. */
    showScreenPreview(session) {
        const video = this.el.localVideo;
        video.srcObject = new MediaStream([session.screenTrack]);
        video.play?.().catch(() => {});
    }

    /** The call shows video from now on (camera turned on here or by the other person). */
    becomeVideo(session) {
        if (session.type === 'video') return;
        session.type = 'video';
        this.attachLocal();
        this.attachRemote();
        // Phone app: keep the camera allowed in the background and use the loudspeaker.
        this.emit('active');
        if (!session.speaker) {
            session.speaker = true;
            this.native?.setSpeaker?.(true);
        }
        this.render();
    }

    toggleSpeaker() {
        const session = this.session;
        if (!session || !this.native?.setSpeaker) return;
        session.speaker = !session.speaker;
        this.native.setSpeaker(session.speaker);
        this.render();
    }

    attachLocal() {
        const session = this.session;
        const video = this.el.localVideo;
        if (session?.screenSharing && session.screenTrack) {
            this.showScreenPreview(session);
            return;
        }
        if (!session?.localStream || session.type !== 'video') {
            video.srcObject = null;
            return;
        }
        if (video.srcObject !== session.localStream) video.srcObject = session.localStream;
        video.play?.().catch(() => {});
        this.render();
    }

    attachRemote() {
        const session = this.session;
        if (!session?.remoteStream) return;

        const target = session.type === 'video' ? this.el.remoteVideo : this.el.remoteAudio;
        const other = session.type === 'video' ? this.el.remoteAudio : this.el.remoteVideo;
        other.srcObject = null;

        if (target.srcObject !== session.remoteStream) target.srcObject = session.remoteStream;
        target.play?.().catch(() => {
            session.needsTap = true;
            this.render();
        });
        if (session.minimized) this.renderMinimized();
    }

    /* ------------------------------------------------------------------ */
    /* Screen                                                              */
    /* ------------------------------------------------------------------ */

    renderRoot() {
        const root = document.createElement('div');
        root.className = 'call-screen';
        root.hidden = true;
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-labelledby', 'call-name');
        root.innerHTML = `
            <div class="call-backdrop" data-call-backdrop></div>
            <video class="call-remote-video" data-call-remote-video autoplay playsinline></video>
            <audio data-call-remote-audio autoplay></audio>

            <div class="call-top">
                <button type="button" class="call-top-btn" data-call-action="minimize" aria-label="Back to chat">${icon('minimize-2')}</button>
                <span class="call-top-center">
                    <span class="call-top-label" data-call-top-label></span>
                    <button type="button" class="call-quality" data-call-action="low-data" data-call-quality hidden></button>
                </span>
                <button type="button" class="call-top-btn" data-call-action="pip" aria-label="Picture in picture" title="Picture in picture">${icon('picture-in-picture-2')}</button>
            </div>

            <div class="call-identity">
                <div class="call-avatar" data-call-avatar></div>
                <h2 class="call-name" id="call-name" data-call-name></h2>
                <p class="call-status" data-call-status aria-live="polite"></p>
                <p class="call-remote-flags" data-call-remote-flags></p>
            </div>

            <div class="call-local" data-call-local>
                <video class="call-local-video" data-call-local-video autoplay playsinline muted></video>
                <span class="call-local-off" data-call-local-off>${icon('video-off')}</span>
            </div>

            <button type="button" class="call-tap-audio" data-call-action="play" hidden>${icon('volume-2')} Tap to hear the call</button>
            <button type="button" class="call-share-banner" data-call-action="screen-stop" data-call-share-banner hidden>${icon('monitor-x')} You're sharing your screen · <strong>Stop</strong></button>
            <button type="button" class="call-video-invite" data-call-action="camera-on" data-call-video-invite hidden>${icon('video')} <span data-call-video-invite-text></span></button>

            <button type="button" class="call-add-person" data-call-action="add-person" hidden>${icon('user-plus')} Add person</button>

            <div class="call-controls" data-call-controls>
                <button type="button" class="call-btn" data-call-action="speaker" aria-pressed="false">
                    <span class="call-btn-icon">${icon('volume-2')}</span><span class="call-btn-label">Speaker</span>
                </button>
                <button type="button" class="call-btn" data-call-action="camera" aria-pressed="false">
                    <span class="call-btn-icon" data-call-camera-icon>${icon('video')}</span><span class="call-btn-label">Camera</span>
                </button>
                <button type="button" class="call-btn" data-call-action="flip">
                    <span class="call-btn-icon">${icon('switch-camera')}</span><span class="call-btn-label">Flip</span>
                </button>
                <button type="button" class="call-btn" data-call-action="screen" aria-pressed="false">
                    <span class="call-btn-icon" data-call-screen-icon>${icon('monitor-up')}</span><span class="call-btn-label" data-call-screen-label>Share</span>
                </button>
                <button type="button" class="call-btn" data-call-action="mute" aria-pressed="false">
                    <span class="call-btn-icon" data-call-mute-icon>${icon('mic')}</span><span class="call-btn-label">Mute</span>
                </button>
                <button type="button" class="call-btn is-end" data-call-action="end">
                    <span class="call-btn-icon">${icon('phone-off')}</span><span class="call-btn-label">End</span>
                </button>
            </div>

            <div class="call-incoming-actions" data-call-incoming-actions>
                <button type="button" class="call-btn is-end" data-call-action="decline">
                    <span class="call-btn-icon">${icon('phone-off')}</span><span class="call-btn-label">Decline</span>
                </button>
                <button type="button" class="call-btn is-accept" data-call-action="accept">
                    <span class="call-btn-icon" data-call-accept-icon>${icon('phone')}</span><span class="call-btn-label">Accept</span>
                </button>
            </div>
        `;
        document.body.appendChild(root);

        const mini = document.createElement('button');
        mini.type = 'button';
        mini.className = 'call-mini';
        mini.hidden = true;
        mini.dataset.callAction = 'restore';
        mini.innerHTML = `<span class="call-mini-dot"></span>${icon('phone')}<span data-call-mini-text></span>`;
        document.body.appendChild(mini);

        // Floating video window while a video call is minimised (K3).
        const pip = document.createElement('div');
        pip.className = 'call-pip';
        pip.hidden = true;
        pip.setAttribute('role', 'region');
        pip.setAttribute('aria-label', 'Video call');
        pip.innerHTML = `
            <video class="call-pip-video" data-call-pip-video autoplay playsinline muted></video>
            <div class="call-pip-avatar" data-call-pip-avatar></div>
            <span class="call-pip-time" data-call-pip-time></span>
            <div class="call-pip-actions">
                <button type="button" class="call-pip-btn" data-call-action="restore" aria-label="Open call" title="Open call">${icon('maximize-2')}</button>
                <button type="button" class="call-pip-btn" data-call-action="mute" data-call-pip-mute aria-label="Mute" title="Mute">${icon('mic')}</button>
                <button type="button" class="call-pip-btn is-end" data-call-action="end" aria-label="End call" title="End call">${icon('phone-off')}</button>
            </div>
        `;
        document.body.appendChild(pip);

        const q = (selector) => root.querySelector(selector);
        this.el = {
            root,
            mini,
            miniText: mini.querySelector('[data-call-mini-text]'),
            pip,
            pipVideo: pip.querySelector('[data-call-pip-video]'),
            pipAvatar: pip.querySelector('[data-call-pip-avatar]'),
            pipTime: pip.querySelector('[data-call-pip-time]'),
            pipMute: pip.querySelector('[data-call-pip-mute]'),
            backdrop: q('[data-call-backdrop]'),
            remoteVideo: q('[data-call-remote-video]'),
            remoteAudio: q('[data-call-remote-audio]'),
            localWrap: q('[data-call-local]'),
            localVideo: q('[data-call-local-video]'),
            avatar: q('[data-call-avatar]'),
            name: q('[data-call-name]'),
            status: q('[data-call-status]'),
            flags: q('[data-call-remote-flags]'),
            topLabel: q('[data-call-top-label]'),
            controls: q('[data-call-controls]'),
            incomingActions: q('[data-call-incoming-actions]'),
            tapAudio: q('[data-call-action="play"]'),
            videoInvite: q('[data-call-video-invite]'),
            quality: q('[data-call-quality]'),
            shareBanner: q('[data-call-share-banner]'),
            videoInviteText: q('[data-call-video-invite-text]'),
        };
    }

    bind() {
        const onAction = (event) => {
            const action = event.target.closest('[data-call-action]')?.dataset.callAction;
            if (!action) return;

            switch (action) {
                case 'accept':
                    this.accept();
                    break;
                case 'decline':
                    this.decline();
                    break;
                case 'end':
                    this.hangUp();
                    break;
                case 'mute':
                    this.toggleMute();
                    break;
                case 'camera':
                    this.toggleCamera();
                    break;
                case 'camera-on':
                    this.turnOnCamera();
                    break;
                case 'screen':
                    this.toggleScreenShare();
                    break;
                case 'screen-stop':
                    this.stopScreenShare();
                    break;
                case 'add-person':
                    if (this.session) this.group?.addToCall(this.session);
                    break;
                case 'low-data':
                    this.toggleLowData();
                    break;
                case 'flip':
                    this.flipCamera();
                    break;
                case 'speaker':
                    this.toggleSpeaker();
                    break;
                case 'minimize':
                    this.minimize();
                    break;
                case 'restore':
                    this.showScreen();
                    break;
                case 'pip':
                    this.enterPictureInPicture();
                    break;
                case 'play':
                    if (this.session) this.session.needsTap = false;
                    this.attachRemote();
                    this.render();
                    break;
                default:
                    break;
            }
        };
        this.el.root.addEventListener('click', onAction);
        this.el.mini.addEventListener('click', onAction);
        this.el.pip.addEventListener('click', (event) => {
            if (this.pipDragged) return;
            // Tapping the video (not a button) opens the call again.
            if (!event.target.closest('[data-call-action]')) this.showScreen();
            else onAction(event);
        });
        this.bindPipDrag();

        // Header buttons and "call again" on call history.
        document.addEventListener('chat:action', (event) => {
            const { action, event: click } = event.detail;
            if (!['call-audio', 'call-video', 'call-back'].includes(action)) return;
            const conversationId = this.chat.active?.id;
            if (!conversationId) return;
            const type = action === 'call-back' ? click.target.closest('[data-call-type]')?.dataset.callType : action === 'call-audio' ? 'audio' : 'video';
            this.startCall(conversationId, type === 'video' ? 'video' : 'audio');
        });

        document.addEventListener('chat:opened', (event) => this.updateHeader(event.detail.conversation));

        // Android back button: minimise the call screen instead of leaving.
        document.addEventListener('app:back', (event) => {
            if (!this.el.root.hidden && this.session) {
                event.preventDefault();
                this.minimize();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !this.el.root.hidden && this.session && this.session.status !== 'ended') {
                this.minimize();
            }
        });

        // Leaving the page ends the call on this device.
        window.addEventListener('pagehide', (event) => {
            const session = this.session;
            if (event.persisted || !session?.call.id || session.status === 'ended') return;
            const body = new FormData();
            body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
            body.append('reason', 'hangup');
            navigator.sendBeacon?.(this.chat.api.url('callEnd', session.call.id), body);
        });

        window.addEventListener('beforeunload', (event) => {
            if (this.session && this.session.status !== 'ended' && !document.documentElement.classList.contains('is-native-app')) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    /** Drag the floating video window anywhere; it stays where it was left. */
    bindPipDrag() {
        const pip = this.el.pip;
        let start = null;

        pip.addEventListener('pointerdown', (event) => {
            if (event.button !== 0 || event.target.closest('[data-call-action]')) return;
            const rect = pip.getBoundingClientRect();
            start = { x: event.clientX, y: event.clientY, left: rect.left, top: rect.top, width: rect.width, height: rect.height };
            this.pipDragged = false;
            pip.setPointerCapture?.(event.pointerId);
        });

        pip.addEventListener('pointermove', (event) => {
            if (!start) return;
            const dx = event.clientX - start.x;
            const dy = event.clientY - start.y;
            if (!this.pipDragged && Math.hypot(dx, dy) < 6) return;
            this.pipDragged = true;
            pip.classList.add('is-dragging');
            this.placePip(start.left + dx, start.top + dy, start.width, start.height);
        });

        const stop = () => {
            if (!start) return;
            start = null;
            pip.classList.remove('is-dragging');
            // The click after a drag must not open the call.
            setTimeout(() => (this.pipDragged = false), 0);
        };
        pip.addEventListener('pointerup', stop);
        pip.addEventListener('pointercancel', stop);

        window.addEventListener('resize', () => {
            if (!this.pipPosition || pip.hidden) return;
            const rect = pip.getBoundingClientRect();
            this.placePip(this.pipPosition.x, this.pipPosition.y, rect.width, rect.height);
        });
    }

    placePip(x, y, width, height) {
        this.pipPosition = clampPipPosition(x, y, width, height, window.innerWidth, window.innerHeight);
        Object.assign(this.el.pip.style, { left: `${this.pipPosition.x}px`, top: `${this.pipPosition.y}px`, right: 'auto', bottom: 'auto' });
    }

    /** Minimised: a floating video window for video calls, the small pill for voice calls. */
    renderMinimized() {
        const session = this.session;
        const minimized = Boolean(session?.minimized);
        const video = minimized && session.type === 'video';
        if (minimized && session.status === 'ended') {
            this.el.miniText.textContent = `${session.peer?.name ?? 'Call'} · ${session.statusText}`;
            this.el.pipTime.textContent = session.statusText;
        }

        this.el.mini.hidden = !minimized || video;
        this.el.pip.hidden = !video;
        document.documentElement.classList.toggle('has-call-mini', minimized && !video);
        document.documentElement.classList.toggle('has-call-pip', video);

        const pipVideo = this.el.pipVideo;
        if (!video) {
            if (pipVideo.srcObject) pipVideo.srcObject = null;
            return;
        }

        if (session.remoteStream && pipVideo.srcObject !== session.remoteStream) {
            pipVideo.srcObject = session.remoteStream;
            pipVideo.play?.().catch(() => {});
        }
        const live = session.remoteVideoLive && (!session.remoteCameraOff || session.remoteScreen);
        this.el.pip.classList.toggle('is-screen', Boolean(session.remoteScreen));
        this.el.pip.classList.toggle('has-video', live);
        this.el.pipAvatar.innerHTML = live ? '' : T.avatar(session.peer ?? {}, 'lg');
        this.el.pipMute.innerHTML = icon(session.muted ? 'mic-off' : 'mic');
        this.el.pipMute.classList.toggle('is-on', Boolean(session.muted));
        this.el.pipMute.setAttribute('aria-label', session.muted ? 'Unmute' : 'Mute');
    }

    get pipSupported() {
        return Boolean(document.pictureInPictureEnabled && this.el?.remoteVideo?.requestPictureInPicture);
    }

    /**
     * The browser's own picture-in-picture window: the other person's video floats over
     * other tabs and apps (desktop Chrome, Edge, Safari).
     */
    async enterPictureInPicture() {
        const session = this.session;
        if (!session || session.type !== 'video' || !this.pipSupported) return;
        try {
            if (document.pictureInPictureElement) {
                await document.exitPictureInPicture();
                return;
            }
            await this.el.remoteVideo.requestPictureInPicture();
        } catch {
            toast.info("Picture in picture isn't available right now.");
        }
    }

    /** Chrome can open picture-in-picture by itself when you switch tabs during a video call. */
    setAutoPictureInPicture(on) {
        try {
            navigator.mediaSession?.setActionHandler?.('enterpictureinpicture', on ? () => this.enterPictureInPicture() : null);
        } catch {
            /* not supported by this browser */
        }
    }

    openScreen() {
        if (this.session) this.session.minimized = false;
        this.showScreen();
        this.updateHeader(this.chat.activeConversation());
    }

    showScreen() {
        if (this.group?.active && !this.session) {
            this.group.show();
            return;
        }
        if (this.session) this.session.minimized = false;
        this.el.root.hidden = false;
        document.documentElement.classList.add('has-call-screen');
        this.renderMinimized();
        this.render();
    }

    hideScreen() {
        this.el.root.hidden = true;
        this.el.mini.hidden = true;
        this.el.pip.hidden = true;
        this.el.pipVideo.srcObject = null;
        document.documentElement.classList.remove('has-call-screen', 'has-call-mini', 'has-call-pip');
    }

    minimize() {
        const session = this.session;
        if (!session || session.status === 'ended') return;
        session.minimized = true;
        this.el.root.hidden = true;
        document.documentElement.classList.remove('has-call-screen');
        this.renderMinimized();
        this.renderDuration();
    }

    setStatus(status, text = '') {
        const session = this.session;
        if (!session || session.status === 'ended') return;
        session.status = status;
        session.statusText = text;
        this.render();
    }

    render() {
        if (!this.el) return;
        const { root } = this.el;
        const incoming = this.incoming;
        const session = this.session;
        const view = session ? session.status : incoming ? 'incoming' : 'idle';
        const type = session?.type ?? incoming?.call.type ?? 'audio';
        const peer = session?.peer ?? incoming?.peer ?? {};

        root.dataset.state = view;
        root.dataset.type = type;
        root.dataset.role = session?.role ?? 'callee';
        root.style.setProperty('--call-hue', String(peer.avatar_hue ?? 230));

        const videoLive = Boolean(session && type === 'video' && session.remoteVideoLive && (!session.remoteCameraOff || session.remoteScreen) && view !== 'ended');
        const sharing = Boolean(session?.screenSharing && view !== 'ended');
        root.classList.toggle('has-remote-video', videoLive);
        root.classList.toggle('has-local-video', Boolean(session && (sharing || (type === 'video' && session.localStream?.getVideoTracks().length)) && view !== 'ended'));
        root.classList.toggle('is-local-off', Boolean(session?.cameraOff) && !sharing);
        root.classList.toggle('is-mirrored', session?.facing !== 'environment' && !sharing);
        root.classList.toggle('is-remote-screen', Boolean(videoLive && session.remoteScreen));
        root.classList.toggle('is-sharing-screen', sharing);

        this.el.avatar.innerHTML = T.avatar(peer, 'xl');
        this.el.name.textContent = peer.name ?? 'Unknown';
        this.el.topLabel.textContent = type === 'video' ? 'Video call' : 'Voice call';

        const room = incoming?.call.room;
        if (view === 'incoming' && room) {
            const others = (room.participants ?? []).filter((p) => Number(p.user_id) !== Number(incoming.call.caller_id)).map((p) => p.name).filter(Boolean);
            this.el.status.textContent = `Group ${type === 'video' ? 'video' : 'voice'} call${others.length ? ` with ${others.join(', ')}` : ''}`;
        } else if (view === 'incoming') {
            this.el.status.textContent = type === 'video' ? 'Incoming video call' : 'Incoming voice call';
        } else if (view === 'connected') {
            this.renderDuration();
        } else {
            this.el.status.textContent = session?.statusText ?? '';
        }

        const flags = [];
        if (session?.noMicrophone && view !== 'ended') flags.push('No microphone on this device');
        if (session && view === 'connected') {
            if (session.remoteMuted) flags.push(`${peer.name ?? 'They'} muted their microphone`);
            if (session.remoteScreen) flags.push(`${peer.name ?? 'They'} is sharing their screen`);
            else if (type === 'video' && session.remoteCameraOff) flags.push('Camera off');
        }
        this.el.flags.textContent = flags.join(' · ');

        this.el.controls.hidden = view === 'incoming' || view === 'idle';
        this.el.incomingActions.hidden = view !== 'incoming';

        const button = (name) => root.querySelector(`[data-call-action="${name}"]`);
        const ended = view === 'ended';
        root.querySelectorAll('.call-controls .call-btn').forEach((btn) => (btn.disabled = ended));

        button('speaker').hidden = !this.native?.setSpeaker;
        button('speaker').setAttribute('aria-pressed', String(Boolean(session?.speaker)));
        // Voice calls get a "Video" button once connected (K2).
        const hasCamera = this.hasLiveCamera(session);
        const cameraOff = type === 'video' && (Boolean(session?.cameraOff) || !hasCamera);
        button('camera').hidden = !session || (type !== 'video' && view !== 'connected');
        button('camera').disabled = ended || Boolean(session?.switchingVideo);
        button('camera').setAttribute('aria-pressed', String(cameraOff));
        button('camera').querySelector('.call-btn-label').textContent = type === 'video' ? 'Camera' : 'Video';
        root.querySelector('[data-call-camera-icon]').innerHTML = icon(cameraOff ? 'video-off' : 'video');
        button('flip').hidden = type !== 'video' || !session?.canFlip || Boolean(session?.cameraOff) || !hasCamera || sharing;
        // Screen sharing (K4): computers only.
        button('screen').hidden = !session || view !== 'connected' || !this.screenShareSupported;
        button('screen').disabled = ended || Boolean(session?.startingShare);
        button('screen').setAttribute('aria-pressed', String(sharing));
        root.querySelector('[data-call-screen-label]').textContent = sharing ? 'Stop' : 'Share';
        root.querySelector('[data-call-screen-icon]').innerHTML = icon(sharing ? 'monitor-x' : 'monitor-up');
        this.el.shareBanner.hidden = !sharing;
        this.renderQuality(session, view);
        root.querySelector('[data-call-action="add-person"]').hidden = !(this.group && session && view === 'connected');
        this.el.videoInvite.hidden = !(session?.remoteTurnedOnVideo && !hasCamera && view === 'connected');
        this.el.videoInviteText.textContent = `${peer.name ?? 'They'} turned on video · Turn on your camera`;
        button('mute').setAttribute('aria-pressed', String(Boolean(session?.muted)));
        button('mute').disabled = ended || Boolean(session?.noMicrophone);
        root.querySelector('[data-call-mute-icon]').innerHTML = icon(session?.muted ? 'mic-off' : 'mic');
        root.querySelector('[data-call-accept-icon]').innerHTML = icon(type === 'video' ? 'video' : 'phone');
        button('minimize').style.visibility = !session || ended ? 'hidden' : '';
        button('pip').style.visibility = session && !ended && type === 'video' && this.pipSupported ? '' : 'hidden';
        this.el.tapAudio.hidden = !session?.needsTap || ended;
        this.setAutoPictureInPicture(Boolean(session && view === 'connected' && type === 'video' && this.pipSupported));
        if (session?.minimized) this.renderMinimized();
    }

    renderDuration() {
        const session = this.session;
        if (!session) return;

        if (session.status === 'connected') {
            const text = formatDuration(this.elapsedSeconds(session));
            this.el.status.textContent = text;
            this.el.miniText.textContent = `${session.peer?.name ?? 'Call'} · ${text}`;
            this.el.pipTime.textContent = text;
        } else {
            this.el.miniText.textContent = `${session.peer?.name ?? 'Call'} · ${session.statusText || 'Calling…'}`;
            this.el.pipTime.textContent = session.statusText || 'Calling…';
        }
    }

    emit(state, call = null) {
        const session = this.session;
        document.dispatchEvent(
            new CustomEvent('call:state', {
                detail: {
                    state,
                    call: call ?? session?.call ?? null,
                    type: call?.type ?? session?.type ?? null,
                    peer: session?.peer ?? this.incoming?.peer ?? null,
                    speaker: session?.speaker ?? false,
                    manager: this,
                },
            }),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Browser notification for a call ringing in a background tab         */
    /* ------------------------------------------------------------------ */

    showBrowserNotification(incoming) {
        if (document.visibilityState === 'visible' || this.native) return;
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        if (this.chat.config.user?.notifications_enabled === false) return;

        try {
            const notification = new Notification(incoming.call.type === 'video' ? 'Incoming video call' : 'Incoming voice call', {
                body: incoming.peer?.name ?? '',
                icon: incoming.peer?.avatar_url || window.App?.config?.icon || '/favicon.svg',
                tag: `call-${incoming.call.id}`,
                requireInteraction: true,
            });
            notification.onclick = () => {
                window.focus();
                this.showScreen();
                notification.close();
            };
            incoming.notification = notification;
        } catch {
            /* notifications unavailable */
        }
    }

    closeBrowserNotification(incoming) {
        incoming?.notification?.close?.();
    }

    /* ------------------------------------------------------------------ */
    /* Restore after reload / opened from a link                           */
    /* ------------------------------------------------------------------ */

    async restore() {
        const params = new URLSearchParams(window.location.search);
        const callId = Number(params.get('call'));

        if (callId) {
            const answer = params.get('answer') === '1';
            params.delete('call');
            params.delete('answer');
            const query = params.toString();
            history.replaceState(history.state, '', `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`);
            await this.resumeFromLink(callId, answer);
            return;
        }

        try {
            const data = await this.get('callsActive');
            const ringing = (data.data ?? []).find((call) => call.status === 'ringing' && Number(call.callee_id) === Number(this.me.id));
            if (ringing) this.onIncoming(ringing, data.ice_servers);
        } catch {
            /* non-critical */
        }
    }

    /* ------------------------------------------------------------------ */
    /* HTTP                                                                */
    /* ------------------------------------------------------------------ */

    post(route, id, data = {}) {
        return axios.post(this.chat.api.url(route, id), data).then((response) => response.data);
    }

    get(route, id, params = {}) {
        const url = id === undefined ? this.chat.api.url(route) : this.chat.api.url(route, id);
        return axios.get(url, { params }).then((response) => response.data);
    }
}

