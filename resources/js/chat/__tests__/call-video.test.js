// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: {} })) } }));

import axios from '../../bootstrap';
import { CallManager } from '../calls';

class FakeStream {
    constructor(tracks = []) {
        this.tracks = [...tracks];
    }
    getTracks() { return this.tracks; }
    getAudioTracks() { return this.tracks.filter((t) => t.kind === 'audio'); }
    getVideoTracks() { return this.tracks.filter((t) => t.kind === 'video'); }
    addTrack(track) { this.tracks.push(track); }
    removeTrack(track) { this.tracks = this.tracks.filter((t) => t !== track); }
}

const track = (kind) => ({ kind, readyState: 'live', enabled: true, stop: vi.fn(function () { this.readyState = 'ended'; }) });

function voiceCall() {
    const chat = {
        me: { id: 1 },
        config: { calls: { enabled: true } },
        api: { has: () => true, url: (name, id) => `/${name}/${id ?? ''}` },
        activeConversation: () => null,
    };
    const manager = new CallManager(chat);
    const sender = { replaceTrack: vi.fn(async () => {}) };
    const session = manager.createSession({ call: { id: 7, type: 'audio', caller_id: 1, callee_client: 'them-01' }, role: 'caller', peer: { id: 2, name: 'Sara' } });
    session.status = 'connected';
    session.localStream = new FakeStream([track('audio')]);
    session.pc = { getTransceivers: () => [{ receiver: { track: { kind: 'audio' } }, sender: {} }, { receiver: { track: { kind: 'video' } }, sender }] };
    manager.session = session;
    manager.native = { setSpeaker: vi.fn() };
    for (const media of [manager.el.remoteVideo, manager.el.remoteAudio, manager.el.localVideo]) {
        Object.defineProperty(media, 'srcObject', { writable: true, value: null });
        media.play = async () => {};
    }
    return { manager, session, sender };
}

beforeEach(() => {
    globalThis.MediaStream = FakeStream;
    axios.post.mockClear();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('K2 voice call → video call', () => {
    it('offers a Video button once a voice call is connected', () => {
        const { manager, session } = voiceCall();
        session.status = 'outgoing';
        manager.render();
        const camera = manager.el.root.querySelector('[data-call-action="camera"]');
        expect(camera.hidden).toBe(true);

        session.status = 'connected';
        manager.render();
        expect(camera.hidden).toBe(false);
        expect(camera.textContent).toContain('Video');
    });

    it('turns the camera on without renegotiating and tells the other side', async () => {
        const { manager, session, sender } = voiceCall();
        const camera = track('video');
        navigator.mediaDevices = { getUserMedia: vi.fn(async () => new FakeStream([camera])), enumerateDevices: async () => [] };
        const events = [];
        document.addEventListener('call:state', (event) => events.push(event.detail.state));

        await manager.turnOnCamera();

        expect(sender.replaceTrack).toHaveBeenCalledWith(camera);
        expect(session.type).toBe('video');
        expect(session.localStream.getVideoTracks()).toEqual([camera]);
        expect(manager.native.setSpeaker).toHaveBeenCalledWith(true);
        expect(events).toContain('active');

        await vi.waitFor(() => expect(axios.post.mock.calls.some(([, body]) => body?.type === 'media')).toBe(true));
        const urls = axios.post.mock.calls.map(([url, body]) => [url, body?.type ?? null]);
        expect(urls).toContainEqual(['/callVideo/7', null]);
        const media = axios.post.mock.calls.find(([, body]) => body?.type === 'media');
        expect(JSON.parse(media[1].payload)).toMatchObject({ video: true, cameraOff: false });

        manager.render();
        expect(manager.el.root.querySelector('[data-call-action="camera"]').textContent).toContain('Camera');
    });

    it('switches to video when the other person turns their camera on and invites you to join', async () => {
        const { manager, session } = voiceCall();

        await manager.processSignal(session, { id: 1, type: 'media', payload: JSON.stringify({ muted: false, cameraOff: false, video: true }) });

        expect(session.type).toBe('video');
        expect(session.remoteTurnedOnVideo).toBe(true);
        expect(manager.el.videoInvite.hidden).toBe(false);
        expect(manager.el.videoInvite.textContent).toContain('Sara turned on video');
    });
});
