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

const track = (kind) => ({ kind, contentHint: '', readyState: 'live', enabled: true, stop: vi.fn(function () { this.readyState = 'ended'; }) });

function videoCall() {
    const chat = {
        me: { id: 1 },
        config: { calls: { enabled: true } },
        api: { has: () => true, url: (name, id) => `/${name}/${id ?? ''}` },
        activeConversation: () => null,
    };
    const manager = new CallManager(chat);
    const sender = { replaceTrack: vi.fn(async () => {}) };
    const camera = track('video');
    const session = manager.createSession({ call: { id: 5, type: 'video', caller_id: 1, callee_client: 'them-01' }, role: 'caller', peer: { id: 2, name: 'Sara' } });
    session.status = 'connected';
    session.localStream = new FakeStream([track('audio'), camera]);
    session.pc = { getTransceivers: () => [{ receiver: { track: { kind: 'video' } }, sender }] };
    manager.session = session;
    for (const media of [manager.el.remoteVideo, manager.el.remoteAudio, manager.el.localVideo, manager.el.pipVideo]) {
        Object.defineProperty(media, 'srcObject', { writable: true, value: null });
        media.play = async () => {};
    }
    return { manager, session, sender, camera };
}

const mediaSignals = () => axios.post.mock.calls.filter(([, body]) => body?.type === 'media').map(([, body]) => JSON.parse(body.payload));

beforeEach(() => {
    globalThis.MediaStream = FakeStream;
    axios.post.mockClear();
});

afterEach(() => {
    document.body.innerHTML = '';
    delete navigator.mediaDevices;
});

describe('K4 screen sharing', () => {
    it('is offered only where the browser can share a screen', () => {
        const { manager } = videoCall();
        const share = manager.el.root.querySelector('[data-call-action="screen"]');

        manager.render();
        expect(share.hidden).toBe(true);

        navigator.mediaDevices = { getDisplayMedia: vi.fn() };
        manager.render();
        expect(share.hidden).toBe(false);

        // Not inside the Android app.
        manager.native = { setSpeaker: vi.fn() };
        manager.render();
        expect(share.hidden).toBe(true);
    });

    it('sends the screen instead of the camera and goes back to the camera when sharing stops', async () => {
        const { manager, session, sender, camera } = videoCall();
        const screen = track('video');
        navigator.mediaDevices = { getDisplayMedia: vi.fn(async () => new FakeStream([screen])) };

        await manager.toggleScreenShare();

        expect(sender.replaceTrack).toHaveBeenLastCalledWith(screen);
        expect(session.screenSharing).toBe(true);
        expect(screen.contentHint).toBe('detail');
        expect(manager.el.shareBanner.hidden).toBe(false);
        expect(manager.el.root.classList.contains('is-sharing-screen')).toBe(true);
        expect(manager.el.root.classList.contains('is-mirrored')).toBe(false);
        await vi.waitFor(() => expect(mediaSignals().at(-1)).toMatchObject({ screen: true }));

        // "Stop sharing" in the browser's own bar ends the track.
        screen.onended();
        await vi.waitFor(() => expect(sender.replaceTrack).toHaveBeenLastCalledWith(camera));
        expect(screen.stop).toHaveBeenCalled();
        expect(session.screenSharing).toBe(false);
        expect(manager.el.shareBanner.hidden).toBe(true);
        await vi.waitFor(() => expect(mediaSignals().at(-1)).toMatchObject({ screen: false }));
    });

    it('does nothing when the person closes the screen picker', async () => {
        const { manager, session, sender } = videoCall();
        navigator.mediaDevices = { getDisplayMedia: vi.fn(async () => { throw Object.assign(new Error('cancel'), { name: 'NotAllowedError' }); }) };

        await manager.toggleScreenShare();

        expect(sender.replaceTrack).not.toHaveBeenCalled();
        expect(session.screenSharing).toBeFalsy();
        expect(document.querySelector('.toast')).toBeNull();
    });

    it("shows the other person's screen uncropped with a label", async () => {
        const { manager, session } = videoCall();
        session.remoteVideoLive = true;

        await manager.processSignal(session, { id: 9, type: 'media', payload: JSON.stringify({ muted: false, cameraOff: true, video: true, screen: true }) });

        expect(manager.el.root.classList.contains('is-remote-screen')).toBe(true);
        expect(manager.el.root.classList.contains('has-remote-video')).toBe(true);
        expect(manager.el.flags.textContent).toBe('Sara is sharing their screen');
    });
});
