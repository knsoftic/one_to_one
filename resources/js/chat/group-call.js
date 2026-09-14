import { errorMessage, formatDuration } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { pickPeople } from './people-picker';
import * as T from './templates';

/**
 * K6 — Group calls (up to 4 people). Every device connects directly to every
 * other device in the call ("mesh"); for each pair, the person who joined later
 * sends the WebRTC offer. People are added by ringing them with a normal call.
 */

const HEARTBEAT_MS = 15_000;
const SIGNAL_POLL_MS = 1500;
const RECONNECT_GRACE_MS = 5000;
const MAX_ICE_RESTARTS = 3;
const END_SCREEN_MS = 1800;

/**
 * Which people in the room this device should be connected to, and who sends the offer.
 *
 * @returns {{key: string, participant: object, offerer: boolean}[]}
 */
export function meshPlan(room, meId, clientId) {
    const participants = room?.participants ?? [];
    const mine = participants.find((p) => Number(p.user_id) === Number(meId) && p.status === 'joined' && p.client_id === clientId);
    if (!mine || room.status !== 'active') return [];

    return participants
        .filter((p) => p.status === 'joined' && p.client_id && !(Number(p.user_id) === Number(meId)))
        .map((p) => ({ key: `${p.user_id}:${p.client_id}`, participant: p, offerer: Number(mine.join_seq) > Number(p.join_seq) }));
}

export class GroupCall {
    constructor(manager) {
        this.manager = manager;
        this.chat = manager.chat;
        this.me = manager.me;
        this.clientId = manager.clientId;
        /** The group call this device takes part in. */
        this.active = null;

        this.renderRoot();
        this.bind();
    }

    get max() {
        return Number(this.manager.config.maxGroupParticipants ?? 4);
    }

    /* ------------------------------------------------------------------ */
    /* Starting and joining                                                */
    /* ------------------------------------------------------------------ */

    /** "Add person" in a one-to-one call. */
    async addToCall(session) {
        const excludeIds = [Number(session.peer?.id)].filter(Boolean);
        const choice = await pickPeople(this.chat, { title: 'Add to call', max: 1, excludeIds, submitLabel: 'Add' });
        if (!choice || this.manager.session !== session || session.status === 'ended') return;

        try {
            const data = await this.manager.post('callParticipantsStore', session.call.id, { user_id: choice.ids[0] });
            this.enterFromSession(session, data.room, data.ice_servers);
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't add them to the call."));
        }
    }

    /** New group call from the Calls tab. */
    async startNew() {
        if (this.manager.busy) {
            toast.info('Finish your current call first.');
            return;
        }
        const choice = await pickPeople(this.chat, { title: 'New group call', max: this.max - 1, chooseType: true });
        if (!choice) return;
        await this.startWith(choice.ids, choice.type);
    }

    /**
     * Call button in a group chat (Phase 4): everyone in the group rings when they fit in
     * a call, otherwise choose who.
     */
    async startForGroup(conversation, type) {
        if (this.manager.busy) {
            toast.info('Finish your current call first.');
            return;
        }
        const me = Number(this.me.id);
        const others = (conversation.group?.members ?? [])
            .filter((m) => m.active && Number(m.user.id) !== me)
            .map((m) => ({ id: Number(m.user.id), name: this.chat.displayName(m.user.id, m.user.name), user: this.chat.decorate(m.user) }));

        if (!others.length) {
            toast.info('There is nobody else in this group to call.');
            return;
        }
        if (others.length <= this.max - 1) {
            await this.startWith(others.map((person) => person.id), type);
            return;
        }
        const choice = await pickPeople(this.chat, { title: `Choose up to ${this.max - 1} people to call`, max: this.max - 1, people: others, submitLabel: type === 'video' ? 'Video call' : 'Voice call' });
        if (choice) await this.startWith(choice.ids, type);
    }

    async startWith(ids, type) {
        const choice = { ids, type };
        const probe = this.manager.createSession({ call: { id: null, type: choice.type }, role: 'caller', peer: {} });
        let localStream;
        try {
            localStream = await this.manager.getMedia(choice.type, probe);
        } catch (error) {
            toast.error(this.manager.mediaError(error, choice.type));
            return;
        }

        try {
            const data = await this.manager.post('callRoomsStore', undefined, { user_ids: choice.ids, type: choice.type, client_id: this.clientId });
            this.begin({ room: data.room, iceServers: data.ice_servers, localStream, type: choice.type, muted: probe.muted, noCamera: probe.noCamera, cameraOff: probe.cameraOff });
        } catch (error) {
            this.manager.stopStream(localStream);
            toast.error(errorMessage(error, "Couldn't start the group call."));
        }
    }

    /** Join the call of a call link (K7). */
    async joinLink(token, type) {
        if (this.manager.busy) {
            toast.info('Finish your current call first.');
            return;
        }
        const probe = this.manager.createSession({ call: { id: null, type }, role: 'caller', peer: {} });
        let localStream;
        try {
            localStream = await this.manager.getMedia(type, probe);
        } catch (error) {
            toast.error(this.manager.mediaError(error, type));
            return;
        }

        try {
            const data = await this.manager.post('callLinkJoin', token, { client_id: this.clientId });
            this.begin({ room: data.room, iceServers: data.ice_servers, localStream, type, muted: probe.muted, noCamera: probe.noCamera, cameraOff: probe.cameraOff });
        } catch (error) {
            this.manager.stopStream(localStream);
            toast.error(errorMessage(error, "Couldn't join the call."));
        }
    }

    /** Answered an invite (the call screen is already open with the camera/microphone). */
    async joinFromSession(session, iceServers = []) {
        try {
            const data = await this.manager.get('callRoomShow', session.call.call_room_id);
            this.enterFromSession(session, data.room, data.ice_servers?.length ? data.ice_servers : iceServers);
        } catch (error) {
            this.manager.teardown(session, errorMessage(error, "Couldn't join the call."), { error: true });
        }
    }

    /**
     * Move a one-to-one call (or an answered invite) into this group call, keeping
     * the camera and microphone running and without ending the call on the server.
     */
    enterFromSession(session, room, iceServers = []) {
        if (this.active) {
            this.onRoomUpdated(room);
            return;
        }
        const m = this.manager;
        Object.values(session.timers).forEach(clearTimeout);
        Object.values(session.intervals).forEach(clearInterval);
        m.tones.stop();
        try {
            session.pc?.close();
        } catch {
            /* already closed */
        }
        session.screenTrack?.stop();
        for (const media of [m.el.remoteVideo, m.el.remoteAudio, m.el.localVideo, m.el.pipVideo]) media.srcObject = null;
        m.session = null;
        m.hideScreen();

        this.begin({
            room,
            iceServers: iceServers.length ? iceServers : session.iceServers,
            localStream: session.localStream,
            type: session.type,
            muted: session.muted,
            cameraOff: session.cameraOff,
            noCamera: session.noCamera,
            speaker: session.speaker,
            callId: session.call.id,
            conversationId: session.call.conversation_id,
        });
    }

    begin({ room, iceServers = [], localStream, type, muted = false, cameraOff = false, noCamera = false, speaker, callId = null, conversationId = null }) {
        const mine = (room.participants ?? []).find((p) => Number(p.user_id) === Number(this.me.id));
        const firstInvite = (room.participants ?? []).find((p) => Number(p.invited_by) === Number(this.me.id) && p.call_id);

        this.active = {
            room,
            roomId: Number(room.id),
            type: type ?? room.type,
            iceServers,
            localStream,
            muted,
            cameraOff,
            noCamera,
            facing: 'user',
            speaker: speaker ?? type === 'video',
            callId: callId ?? mine?.call_id ?? firstInvite?.call_id ?? null,
            conversationId: conversationId ?? mine?.conversation_id ?? firstInvite?.conversation_id ?? null,
            peers: new Map(),
            tiles: new Map(),
            handled: new Set(),
            cursor: 0,
            status: 'connecting',
            connectedAt: null,
            minimized: false,
            intervals: {},
        };
        const state = this.active;
        localStream?.getAudioTracks().forEach((track) => (track.enabled = !muted));

        state.intervals.heartbeat = setInterval(() => this.heartbeat(), HEARTBEAT_MS);
        state.intervals.signals = setInterval(() => {
            if (this.chat.realtime?.polling || [...state.peers.values()].some((peer) => peer.state !== 'connected')) this.fetchSignals();
        }, SIGNAL_POLL_MS);
        state.intervals.duration = setInterval(() => this.renderDuration(), 1000);

        this.show();
        this.emit('active');
        this.onRoomUpdated(room);
        this.fetchSignals();
        this.manager.updateHeader(this.chat.activeConversation());
    }

    /* ------------------------------------------------------------------ */
    /* Room state                                                          */
    /* ------------------------------------------------------------------ */

    onRoomUpdated(room) {
        if (!room?.id) return;
        const state = this.active;

        if (!state) {
            // Someone added a person to my one-to-one call.
            const session = this.manager.session;
            const mine = (room.participants ?? []).find((p) => Number(p.user_id) === Number(this.me.id) && p.status === 'joined' && p.client_id === this.clientId);
            if (room.status === 'active' && session && session.status !== 'ended' && mine && Number(mine.call_id) === Number(session.call.id)) {
                this.enterFromSession(session, room);
            }
            return;
        }
        if (Number(room.id) !== state.roomId || state.status === 'ended') return;

        state.room = room;
        const plan = meshPlan(room, this.me.id, this.clientId);
        const mine = (room.participants ?? []).find((p) => Number(p.user_id) === Number(this.me.id));
        if (room.status !== 'active' || mine?.status !== 'joined' || mine.client_id !== this.clientId) {
            this.end(room.status !== 'active' ? 'Call ended' : 'You left the call');
            return;
        }

        const wanted = new Map(plan.map((entry) => [entry.key, entry]));
        for (const [key, peer] of state.peers) {
            if (!wanted.has(key)) this.closePeer(peer);
        }
        for (const [key, entry] of wanted) {
            if (state.peers.has(key)) continue;
            const peer = this.createPeer(state, entry.participant, entry.offerer);
            if (entry.offerer) this.sendOffer(peer).catch((error) => console.warn('Group call offer failed', error));
        }

        // Voice group call: switch the layout when anyone turns a camera on.
        if (room.type === 'video' && state.type !== 'video') this.becomeVideo(state);
        this.render();
    }

    async refreshRoom() {
        const state = this.active;
        if (!state) return;
        try {
            const data = await this.manager.get('callRoomShow', state.roomId);
            if (data.ice_servers?.length) state.iceServers = data.ice_servers;
            this.onRoomUpdated(data.room);
        } catch (error) {
            if ([403, 404].includes(error?.response?.status)) this.end('Call ended');
        }
    }

    async heartbeat() {
        const state = this.active;
        if (!state) return;
        try {
            const data = await this.manager.post('callRoomHeartbeat', state.roomId);
            this.onRoomUpdated(data.room);
        } catch (error) {
            if ([403, 404].includes(error?.response?.status)) this.end('Call ended');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Connections                                                         */
    /* ------------------------------------------------------------------ */

    createPeer(state, participant, offerer) {
        const pc = new RTCPeerConnection({ iceServers: state.iceServers ?? [], iceCandidatePoolSize: 1 });
        const peer = {
            key: `${participant.user_id}:${participant.client_id}`,
            userId: Number(participant.user_id),
            clientId: participant.client_id,
            joinSeq: Number(participant.join_seq),
            offerer,
            pc,
            remoteStream: null,
            state: 'connecting',
            muted: false,
            cameraOff: false,
            videoLive: false,
            restarts: 0,
            pendingCandidates: [],
            sdpInFlight: Promise.resolve(),
            queue: Promise.resolve(),
            audio: null,
            timers: {},
        };
        state.peers.set(peer.key, peer);

        state.localStream?.getTracks().forEach((track) => pc.addTrack(track, state.localStream));
        if (!state.localStream?.getAudioTracks().length) pc.addTransceiver('audio', { direction: 'recvonly' });
        // A video line that sends nothing until a camera is on (as in one-to-one calls, K2).
        if (offerer && !state.localStream?.getVideoTracks().length) pc.addTransceiver('video', { direction: 'sendrecv' });

        pc.onicecandidate = ({ candidate }) => {
            if (candidate && this.active === state) this.sendSignal(peer, 'candidate', candidate.toJSON());
        };

        pc.ontrack = (event) => {
            const stream = event.streams?.[0];
            if (stream) {
                peer.remoteStream = stream;
            } else {
                peer.remoteStream ??= new MediaStream();
                peer.remoteStream.addTrack(event.track);
            }
            this.attachAudio(peer);

            if (event.track.kind === 'video') {
                const update = () => {
                    peer.videoLive = !event.track.muted && event.track.readyState === 'live';
                    if (peer.videoLive && state.type !== 'video') this.becomeVideo(state);
                    this.render();
                };
                event.track.onunmute = update;
                event.track.onmute = update;
                event.track.onended = update;
                update();
            }
        };

        pc.onconnectionstatechange = () => this.onPeerState(state, peer);
        return peer;
    }

    closePeer(peer) {
        const state = this.active;
        Object.values(peer.timers).forEach(clearTimeout);
        try {
            peer.pc.close();
        } catch {
            /* already closed */
        }
        if (peer.audio) {
            peer.audio.srcObject = null;
            peer.audio.remove();
        }
        state?.peers.delete(peer.key);
        state?.tiles.get(peer.key)?.remove();
        state?.tiles.delete(peer.key);
    }

    attachAudio(peer) {
        if (!peer.remoteStream) return;
        if (!peer.audio) {
            peer.audio = document.createElement('audio');
            peer.audio.autoplay = true;
            this.el.audio.appendChild(peer.audio);
        }
        if (peer.audio.srcObject !== peer.remoteStream) peer.audio.srcObject = peer.remoteStream;
        peer.audio.play?.().catch(() => {});
    }

    async sendOffer(peer, { iceRestart = false } = {}) {
        const pc = peer.pc;
        if (pc.signalingState === 'closed') return;
        const offer = await pc.createOffer({ iceRestart });
        await pc.setLocalDescription(offer);
        await this.sendSignal(peer, 'offer', { type: offer.type, sdp: offer.sdp });
    }

    onPeerState(state, peer) {
        if (this.active !== state || !state.peers.has(peer.key)) return;
        const connection = peer.pc.connectionState;

        if (connection === 'connected') {
            clearTimeout(peer.timers.reconnect);
            peer.state = 'connected';
            peer.restarts = 0;
            this.sendMediaState(peer);
            if (!state.connectedAt) {
                state.connectedAt = Date.now();
                state.status = 'connected';
                this.emit('connected');
            }
        } else if (connection === 'disconnected') {
            peer.state = 'reconnecting';
            clearTimeout(peer.timers.reconnect);
            peer.timers.reconnect = setTimeout(() => this.restartIce(state, peer), RECONNECT_GRACE_MS);
        } else if (connection === 'failed') {
            peer.state = 'reconnecting';
            this.restartIce(state, peer);
        }
        this.render();
    }

    restartIce(state, peer) {
        if (this.active !== state || peer.pc.connectionState === 'connected') return;
        // Only the person who sends offers restarts; the other side answers.
        if (!peer.offerer) return;
        if (peer.restarts >= MAX_ICE_RESTARTS) {
            peer.state = 'failed';
            this.render();
            return;
        }
        peer.restarts++;
        this.sendOffer(peer, { iceRestart: true }).catch(() => {});
    }

    /* ------------------------------------------------------------------ */
    /* Signaling                                                           */
    /* ------------------------------------------------------------------ */

    async sendSignal(peer, type, payload) {
        const state = this.active;
        if (!state || state.status === 'ended') return;

        const body = { client_id: this.clientId, to_user_id: peer.userId, to_client: peer.clientId, type, payload: JSON.stringify(payload) };
        const send = () => this.manager.post('callRoomSignalsStore', state.roomId, body);

        try {
            if (type === 'offer' || type === 'answer') {
                peer.sdpInFlight = send();
                await peer.sdpInFlight;
            } else {
                await peer.sdpInFlight.catch(() => {});
                await send();
            }
        } catch (error) {
            if ([404, 409].includes(error?.response?.status)) this.refreshRoom();
        }
    }

    onSignal(signal) {
        const state = this.active;
        if (!state || Number(signal.call_room_id) !== state.roomId) return;
        if (signal.to_client && signal.to_client !== this.clientId) return;
        if (signal.payload == null) {
            this.fetchSignals();
            return;
        }
        state.queue = (state.queue ?? Promise.resolve()).then(() => this.processSignal(state, signal)).catch((error) => {
            console.warn('Group call signal failed', error);
        });
    }

    async fetchSignals() {
        const state = this.active;
        if (!state || state.fetching) return;
        state.fetching = true;
        try {
            const data = await this.manager.get('callRoomSignals', state.roomId, { client_id: this.clientId, after: state.cursor });
            for (const signal of data.data ?? []) {
                state.cursor = Math.max(state.cursor, Number(signal.id));
                this.onSignal({ ...signal, call_room_id: state.roomId });
            }
            if (data.status === 'ended') this.refreshRoom();
        } catch (error) {
            if ([403, 404].includes(error?.response?.status)) this.end('Call ended');
        } finally {
            state.fetching = false;
        }
    }

    async processSignal(state, signal) {
        if (this.active !== state || state.handled.has(signal.id)) return;
        state.handled.add(signal.id);

        let data;
        try {
            data = JSON.parse(signal.payload);
        } catch {
            return;
        }

        const key = `${signal.sender_id}:${signal.from_client}`;
        let peer = state.peers.get(key);
        if (!peer && signal.type === 'offer') {
            // Someone new: learn about them first.
            await this.refreshRoom();
            peer = state.peers.get(key);
        }
        if (!peer) return;
        const pc = peer.pc;

        switch (signal.type) {
            case 'offer': {
                if (peer.offerer) return;
                await pc.setRemoteDescription(data);
                pc.getTransceivers?.().forEach((transceiver) => {
                    if (transceiver.receiver?.track?.kind === 'video' && ['recvonly', 'inactive'].includes(transceiver.direction)) transceiver.direction = 'sendrecv';
                });
                await this.flushCandidates(peer);
                const answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await this.sendSignal(peer, 'answer', { type: answer.type, sdp: answer.sdp });
                break;
            }
            case 'answer':
                if (pc.signalingState !== 'have-local-offer') return;
                await pc.setRemoteDescription(data);
                await this.flushCandidates(peer);
                break;
            case 'candidate':
                if (!pc.remoteDescription) {
                    peer.pendingCandidates.push(data);
                    return;
                }
                await pc.addIceCandidate(data).catch(() => {});
                break;
            case 'media':
                peer.muted = Boolean(data.muted);
                peer.cameraOff = Boolean(data.cameraOff);
                if (data.video && state.type !== 'video') this.becomeVideo(state);
                this.render();
                break;
            default:
                break;
        }
    }

    async flushCandidates(peer) {
        for (const candidate of peer.pendingCandidates.splice(0)) {
            await peer.pc.addIceCandidate(candidate).catch(() => {});
        }
    }

    sendMediaState(peer = null) {
        const state = this.active;
        if (!state) return;
        const payload = { muted: state.muted, cameraOff: state.cameraOff, video: state.type === 'video' };
        for (const target of peer ? [peer] : state.peers.values()) this.sendSignal(target, 'media', payload);
    }

    /* ------------------------------------------------------------------ */
    /* Controls                                                            */
    /* ------------------------------------------------------------------ */

    toggleMute() {
        const state = this.active;
        if (!state || state.status === 'ended') return;
        if (!state.localStream?.getAudioTracks().length) {
            toast.info('No microphone found on this device.');
            return;
        }
        state.muted = !state.muted;
        state.localStream.getAudioTracks().forEach((track) => (track.enabled = !state.muted));
        this.sendMediaState();
        this.render();
    }

    hasLiveCamera(state = this.active) {
        return Boolean(state?.localStream?.getVideoTracks().some((track) => track.readyState === 'live'));
    }

    videoSenders(state) {
        return [...state.peers.values()]
            .map((peer) => peer.pc.getTransceivers?.().find((t) => t.receiver?.track?.kind === 'video' && !t.stopped)?.sender)
            .filter(Boolean);
    }

    async toggleCamera() {
        const state = this.active;
        if (!state || state.status === 'ended') return;
        if (this.hasLiveCamera(state)) {
            state.cameraOff = !state.cameraOff;
            state.localStream.getVideoTracks().forEach((track) => (track.enabled = !state.cameraOff));
            this.sendMediaState();
            this.render();
            return;
        }
        await this.useCamera(state, 'user');
    }

    async flipCamera() {
        const state = this.active;
        if (!state || !this.hasLiveCamera(state) || state.flipping) return;
        state.flipping = true;
        state.localStream.getVideoTracks().forEach((track) => track.stop());
        try {
            await this.useCamera(state, state.facing === 'user' ? 'environment' : 'user');
        } finally {
            state.flipping = false;
        }
    }

    /** Start (or switch) the camera and send it to everyone in the call. */
    async useCamera(state, facing) {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ video: this.manager.videoConstraints(facing) });
            const track = stream.getVideoTracks()[0];
            if (this.active !== state) {
                track.stop();
                return;
            }
            await Promise.all(this.videoSenders(state).map((sender) => sender.replaceTrack(track).catch(() => {})));
            state.localStream ??= new MediaStream();
            state.localStream.getVideoTracks().forEach((old) => {
                old.stop();
                state.localStream.removeTrack(old);
            });
            state.localStream.addTrack(track);
            Object.assign(state, { facing, cameraOff: false, noCamera: false });
            this.becomeVideo(state);
            this.sendMediaState();
        } catch (error) {
            toast.error(['NotAllowedError', 'SecurityError'].includes(error?.name) ? 'Allow camera access to turn on video.' : "Couldn't use your camera.");
        }
        this.render();
    }

    becomeVideo(state) {
        if (state.type === 'video') return;
        state.type = 'video';
        this.emit('active');
        if (!state.speaker) {
            state.speaker = true;
            this.manager.native?.setSpeaker?.(true);
        }
        this.render();
    }

    toggleSpeaker() {
        const state = this.active;
        if (!state || !this.manager.native?.setSpeaker) return;
        state.speaker = !state.speaker;
        this.manager.native.setSpeaker(state.speaker);
        this.render();
    }

    async addPerson() {
        const state = this.active;
        if (!state || state.status === 'ended') return;
        const taken = (state.room.participants ?? []).filter((p) => ['joined', 'ringing'].includes(p.status));
        if (taken.length >= this.max) {
            toast.info(`A group call can have up to ${this.max} people.`);
            return;
        }
        const choice = await pickPeople(this.chat, { title: 'Add to call', max: 1, excludeIds: taken.map((p) => p.user_id), submitLabel: 'Add' });
        if (!choice || this.active !== state) return;
        try {
            const data = await this.manager.post('callRoomInvite', state.roomId, { user_id: choice.ids[0] });
            this.onRoomUpdated(data.room);
        } catch (error) {
            toast.error(errorMessage(error, "Couldn't add them to the call."));
        }
    }

    leave() {
        const state = this.active;
        if (!state || state.status === 'ended') return;
        this.manager.post('callRoomLeave', state.roomId).catch(() => {});
        this.end('Call ended');
    }

    end(text) {
        const state = this.active;
        if (!state || state.status === 'ended') return;
        const duration = state.connectedAt ? Math.floor((Date.now() - state.connectedAt) / 1000) : 0;
        state.status = 'ended';
        state.endText = duration ? `${text} · ${formatDuration(duration)}` : text;

        Object.values(state.intervals).forEach(clearInterval);
        [...state.peers.values()].forEach((peer) => this.closePeer(peer));
        this.manager.stopStream(state.localStream);
        this.el.localVideo.srcObject = null;
        this.manager.tones.play('end');
        this.render();
        this.emit('ended');

        setTimeout(() => {
            if (this.active !== state) return;
            this.active = null;
            this.hide();
            this.manager.updateHeader(this.chat.activeConversation());
        }, END_SCREEN_MS);
    }

    /* ------------------------------------------------------------------ */
    /* Screen                                                              */
    /* ------------------------------------------------------------------ */

    renderRoot() {
        const root = document.createElement('div');
        root.className = 'call-screen group-call';
        root.hidden = true;
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Group call');
        root.innerHTML = `
            <div class="call-backdrop"></div>
            <div class="call-top">
                <button type="button" class="call-top-btn" data-group-action="minimize" aria-label="Back to chat">${icon('minimize-2')}</button>
                <span class="call-top-center">
                    <span class="call-top-label">Group call</span>
                    <span class="group-call-status" data-group-status aria-live="polite"></span>
                </span>
                <button type="button" class="call-top-btn" data-group-action="add" aria-label="Add person" title="Add person">${icon('user-plus')}</button>
            </div>
            <div class="group-grid" data-group-grid></div>
            <div class="call-controls">
                <button type="button" class="call-btn" data-group-action="speaker" aria-pressed="false">
                    <span class="call-btn-icon">${icon('volume-2')}</span><span class="call-btn-label">Speaker</span>
                </button>
                <button type="button" class="call-btn" data-group-action="camera" aria-pressed="false">
                    <span class="call-btn-icon" data-group-camera-icon>${icon('video')}</span><span class="call-btn-label" data-group-camera-label>Video</span>
                </button>
                <button type="button" class="call-btn" data-group-action="flip">
                    <span class="call-btn-icon">${icon('switch-camera')}</span><span class="call-btn-label">Flip</span>
                </button>
                <button type="button" class="call-btn" data-group-action="mute" aria-pressed="false">
                    <span class="call-btn-icon" data-group-mute-icon>${icon('mic')}</span><span class="call-btn-label">Mute</span>
                </button>
                <button type="button" class="call-btn is-end" data-group-action="end">
                    <span class="call-btn-icon">${icon('phone-off')}</span><span class="call-btn-label">End</span>
                </button>
            </div>
            <div class="group-call-audio" data-group-audio hidden></div>
        `;
        document.body.appendChild(root);

        const localVideo = document.createElement('video');
        localVideo.autoplay = true;
        localVideo.muted = true;
        localVideo.playsInline = true;
        localVideo.className = 'group-tile-video is-local';

        const q = (selector) => root.querySelector(selector);
        this.el = {
            root,
            grid: q('[data-group-grid]'),
            status: q('[data-group-status]'),
            audio: q('[data-group-audio]'),
            add: q('[data-group-action="add"]'),
            localVideo,
        };
    }

    bind() {
        this.el.root.addEventListener('click', (event) => {
            const action = event.target.closest('[data-group-action]')?.dataset.groupAction;
            switch (action) {
                case 'minimize': return this.minimize();
                case 'add': return this.addPerson();
                case 'speaker': return this.toggleSpeaker();
                case 'camera': return this.toggleCamera();
                case 'flip': return this.flipCamera();
                case 'mute': return this.toggleMute();
                case 'end': return this.leave();
                default: return null;
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && this.active && !this.el.root.hidden && this.active.status !== 'ended') this.minimize();
        });
        document.addEventListener('app:back', (event) => {
            if (this.active && !this.el.root.hidden && this.active.status !== 'ended') {
                event.preventDefault();
                this.minimize();
            }
        });
        window.addEventListener('pagehide', (event) => {
            const state = this.active;
            if (event.persisted || !state || state.status === 'ended') return;
            const body = new FormData();
            body.append('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
            navigator.sendBeacon?.(this.chat.api.url('callRoomLeave', state.roomId), body);
        });
    }

    show() {
        if (!this.active) return;
        this.active.minimized = false;
        this.el.root.hidden = false;
        this.manager.el.mini.hidden = true;
        document.documentElement.classList.add('has-call-screen');
        document.documentElement.classList.remove('has-call-mini');
        this.render();
    }

    hide() {
        this.el.root.hidden = true;
        this.manager.el.mini.hidden = true;
        this.el.grid.innerHTML = '';
        document.documentElement.classList.remove('has-call-screen', 'has-call-mini');
    }

    minimize() {
        const state = this.active;
        if (!state || state.status === 'ended') return;
        state.minimized = true;
        this.el.root.hidden = true;
        this.manager.el.mini.hidden = false;
        document.documentElement.classList.remove('has-call-screen');
        document.documentElement.classList.add('has-call-mini');
        this.renderDuration();
    }

    people(state) {
        return (state.room?.participants ?? []).filter((p) => ['joined', 'ringing'].includes(p.status));
    }

    render() {
        const state = this.active;
        if (!state) return;
        const root = this.el.root;
        const ended = state.status === 'ended';
        const people = this.people(state);
        const hasCamera = this.hasLiveCamera(state);

        root.dataset.state = ended ? 'ended' : state.status;
        root.dataset.type = state.type;

        // Tiles: me first, then everyone else in the order they joined.
        const tiles = [{ key: 'me', me: true }, ...people.filter((p) => Number(p.user_id) !== Number(this.me.id)).map((p) => ({ key: p.status === 'joined' ? `${p.user_id}:${p.client_id}` : `ringing:${p.user_id}`, participant: p }))];
        const keep = new Set(tiles.map((tile) => tile.key));
        for (const [key, element] of state.tiles) {
            if (!keep.has(key)) {
                element.remove();
                state.tiles.delete(key);
            }
        }

        this.el.grid.dataset.count = String(tiles.length);
        tiles.forEach((tile, index) => {
            let element = state.tiles.get(tile.key);
            if (!element) {
                element = document.createElement('div');
                element.className = 'group-tile';
                element.innerHTML = '<div class="group-tile-avatar"></div><span class="group-tile-name"></span><span class="group-tile-flag"></span>';
                state.tiles.set(tile.key, element);
            }
            if (this.el.grid.children[index] !== element) this.el.grid.insertBefore(element, this.el.grid.children[index] ?? null);
            this.renderTile(state, element, tile, hasCamera);
        });

        this.renderDuration();

        const button = (name) => root.querySelector(`[data-group-action="${name}"]`);
        root.querySelectorAll('.call-controls .call-btn').forEach((btn) => (btn.disabled = ended));
        button('speaker').hidden = !this.manager.native?.setSpeaker;
        button('speaker').setAttribute('aria-pressed', String(Boolean(state.speaker)));
        const cameraOff = state.type === 'video' && (state.cameraOff || !hasCamera);
        button('camera').setAttribute('aria-pressed', String(cameraOff));
        root.querySelector('[data-group-camera-label]').textContent = state.type === 'video' ? 'Camera' : 'Video';
        root.querySelector('[data-group-camera-icon]').innerHTML = icon(cameraOff ? 'video-off' : 'video');
        button('flip').hidden = !hasCamera || state.cameraOff;
        button('mute').setAttribute('aria-pressed', String(state.muted));
        root.querySelector('[data-group-mute-icon]').innerHTML = icon(state.muted ? 'mic-off' : 'mic');
        this.el.add.style.visibility = ended || people.length >= this.max ? 'hidden' : '';
    }

    renderTile(state, element, tile, hasCamera) {
        const video = element.querySelector('video');
        let stream = null;
        let mirrored = false;
        let user;
        let status = '';
        let muted = false;

        if (tile.me) {
            user = { ...this.me, name: 'You' };
            if (hasCamera && !state.cameraOff) {
                stream = state.localStream;
                mirrored = state.facing !== 'environment';
            }
            muted = state.muted;
        } else {
            const p = tile.participant;
            user = this.chat.decorate?.(p.user ?? {}) ?? p.user ?? {};
            const peer = state.peers.get(tile.key);
            if (p.status === 'ringing') status = 'Ringing…';
            else if (!peer || peer.state === 'connecting') status = 'Connecting…';
            else if (peer.state === 'reconnecting') status = 'Reconnecting…';
            else if (peer.state === 'failed') status = "Couldn't connect";
            if (peer?.videoLive && !peer.cameraOff) stream = peer.remoteStream;
            muted = Boolean(peer?.muted);
        }

        element.classList.toggle('has-video', Boolean(stream));
        element.classList.toggle('is-ringing', Boolean(status === 'Ringing…'));
        element.style.setProperty('--call-hue', String(user.avatar_hue ?? 230));

        if (stream) {
            let target = video;
            if (!target) {
                target = tile.me ? this.el.localVideo : Object.assign(document.createElement('video'), { autoplay: true, muted: true, playsInline: true, className: 'group-tile-video' });
                element.prepend(target);
            }
            target.classList.toggle('is-mirrored', mirrored);
            if (target.srcObject !== stream) {
                target.srcObject = stream;
                target.play?.().catch(() => {});
            }
        } else if (video) {
            video.srcObject = null;
            video.remove();
        }

        const avatar = element.querySelector('.group-tile-avatar');
        const avatarKey = `${user.id}:${user.avatar_url ?? ''}`;
        if (avatar.dataset.user !== avatarKey) {
            avatar.dataset.user = avatarKey;
            avatar.innerHTML = T.avatar(user, 'lg');
        }
        element.querySelector('.group-tile-name').textContent = status ? `${user.name ?? 'Someone'} · ${status}` : user.name ?? 'Someone';
        element.querySelector('.group-tile-flag').innerHTML = muted ? icon('mic-off') : '';
    }

    renderDuration() {
        const state = this.active;
        if (!state) return;
        const participants = state.room?.participants ?? [];
        const joined = participants.filter((p) => p.status === 'joined').length;
        const ringing = participants.some((p) => p.status === 'ringing');
        let text;
        if (state.status === 'ended') text = state.endText;
        else if (joined > 1 && state.connectedAt) text = `${formatDuration(Math.floor((Date.now() - state.connectedAt) / 1000))} · ${joined} people`;
        else if (joined > 1) text = 'Connecting…';
        else text = ringing ? 'Ringing…' : 'Waiting for others to join…';

        this.el.status.textContent = text;
        this.manager.el.miniText.textContent = `Group call · ${text}`;
    }

    emit(stateName) {
        const state = this.active;
        document.dispatchEvent(
            new CustomEvent('call:state', {
                detail: {
                    state: stateName,
                    call: { id: state?.callId ?? 0, conversation_id: state?.conversationId ?? 0, room_id: state?.roomId ?? null },
                    type: state?.type ?? 'audio',
                    peer: { name: 'Group call' },
                    speaker: Boolean(state?.speaker),
                    manager: this.manager,
                    group: true,
                },
            }),
        );
    }
}

