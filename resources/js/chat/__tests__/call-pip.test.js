// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: {} })) } }));

import { CallManager, clampPipPosition } from '../calls';

function call(type) {
    const chat = {
        me: { id: 1 },
        config: { calls: { enabled: true } },
        api: { has: () => true, url: (name, id) => `/${name}/${id ?? ''}` },
        activeConversation: () => null,
    };
    const manager = new CallManager(chat);
    const session = manager.createSession({ call: { id: 3, type, caller_id: 1 }, role: 'caller', peer: { id: 2, name: 'Sara', initials: 'S' } });
    session.status = 'connected';
    session.remoteStream = { id: 'remote' };
    session.remoteVideoLive = true;
    manager.session = session;
    for (const media of [manager.el.remoteVideo, manager.el.remoteAudio, manager.el.localVideo, manager.el.pipVideo]) {
        Object.defineProperty(media, 'srcObject', { writable: true, value: null });
        media.play = async () => {};
    }
    return { manager, session };
}

afterEach(() => {
    document.body.innerHTML = '';
    document.documentElement.className = '';
});

describe('K3 picture-in-picture', () => {
    it('keeps the floating window inside the screen', () => {
        expect(clampPipPosition(-50, -20, 200, 260, 1000, 700)).toEqual({ x: 8, y: 8 });
        expect(clampPipPosition(950, 690, 200, 260, 1000, 700)).toEqual({ x: 792, y: 432 });
        expect(clampPipPosition(300, 200, 200, 260, 1000, 700)).toEqual({ x: 300, y: 200 });
    });

    it('minimising a video call shows the floating video window with the other person', () => {
        const { manager, session } = call('video');
        manager.minimize();

        expect(manager.el.root.hidden).toBe(true);
        expect(manager.el.pip.hidden).toBe(false);
        expect(manager.el.mini.hidden).toBe(true);
        expect(manager.el.pipVideo.srcObject).toBe(session.remoteStream);
        expect(manager.el.pip.classList.contains('has-video')).toBe(true);
        expect(document.documentElement.classList.contains('has-call-pip')).toBe(true);

        // Mute from the floating window, then tap the video to open the call again.
        manager.el.pip.querySelector('[data-call-action="mute"]').click();
        expect(session.muted).toBe(true);
        expect(manager.el.pipMute.classList.contains('is-on')).toBe(true);

        manager.el.pip.querySelector('.call-pip-video').click();
        expect(manager.el.root.hidden).toBe(false);
        expect(manager.el.pip.hidden).toBe(true);
    });

    it('voice calls keep the small pill and switch to the window when video starts', () => {
        const { manager, session } = call('audio');
        manager.minimize();
        expect(manager.el.mini.hidden).toBe(false);
        expect(manager.el.pip.hidden).toBe(true);

        manager.becomeVideo(session);
        expect(manager.el.mini.hidden).toBe(true);
        expect(manager.el.pip.hidden).toBe(false);
    });

    it('offers the browser picture-in-picture button only for video calls where it works', () => {
        const { manager, session } = call('video');
        const pipButton = manager.el.root.querySelector('[data-call-action="pip"]');

        manager.render();
        expect(pipButton.style.visibility).toBe('hidden');

        Object.defineProperty(document, 'pictureInPictureEnabled', { configurable: true, value: true });
        manager.el.remoteVideo.requestPictureInPicture = vi.fn(async () => ({}));
        manager.render();
        expect(pipButton.style.visibility).toBe('');

        pipButton.click();
        expect(manager.el.remoteVideo.requestPictureInPicture).toHaveBeenCalled();

        session.type = 'audio';
        manager.render();
        expect(pipButton.style.visibility).toBe('hidden');
        delete document.pictureInPictureEnabled;
    });
});
