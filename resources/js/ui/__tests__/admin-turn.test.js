// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initAdminTurn, relayAddress, testTurnServers } from '../admin-turn';

const TURN = [{ urls: ['turn:turn.example.com:3478?transport=udp'], username: '1:admin-1', credential: 'x' }];

/** A stand-in RTCPeerConnection that plays back ICE events like Chrome does. */
const fakePeerConnection = (events) => {
    const instances = [];
    class FakePeerConnection {
        constructor(config) {
            this.config = config;
            this.listeners = {};
            this.closed = false;
            instances.push(this);
        }
        addEventListener(type, listener) {
            (this.listeners[type] ??= []).push(listener);
        }
        createDataChannel() {}
        createOffer() {
            return Promise.resolve({ type: 'offer' });
        }
        setLocalDescription() {
            queueMicrotask(() => events.forEach(([type, event]) => this.listeners[type]?.forEach((listener) => listener(event))));
            return Promise.resolve();
        }
        close() {
            this.closed = true;
        }
    }
    return { FakePeerConnection, instances };
};

describe('Admin TURN check', () => {
    afterEach(() => {
        document.body.innerHTML = '';
        vi.useRealTimers();
    });

    it('reads relay addresses from candidate lines', () => {
        expect(relayAddress('candidate:3456816692 1 udp 50339839 203.0.113.7 49170 typ relay raddr 0.0.0.0 rport 0 generation 0')).toBe('203.0.113.7:49170');
        expect(relayAddress('candidate:1 1 udp 2122260223 192.168.1.5 54321 typ host generation 0')).toBeNull();
        expect(relayAddress(undefined)).toBeNull();
    });

    it('passes when a relay comes back, ignoring the normal password challenge', async () => {
        const { FakePeerConnection, instances } = fakePeerConnection([
            ['icecandidateerror', { url: 'stun:turn.example.com:3478', errorCode: 1, errorText: 'Unauthorized' }],
            ['icecandidate', { candidate: { candidate: 'candidate:1 1 udp 50339839 203.0.113.7 49170 typ relay raddr 0.0.0.0 rport 0' } }],
            ['icecandidate', { candidate: null }],
        ]);

        const result = await testTurnServers(TURN, { RTCPeerConnection: FakePeerConnection });

        expect(result).toEqual({ ok: true, relays: ['203.0.113.7:49170'], errors: [] });
        expect(instances[0].config).toEqual({ iceServers: TURN, iceTransportPolicy: 'relay' });
        expect(instances[0].closed).toBe(true);
    });

    it('explains a wrong secret and a server that does not answer', async () => {
        const wrong = fakePeerConnection([
            ['icecandidateerror', { url: 'stun:turn.example.com:3478', errorCode: 1, errorText: 'Unauthorized' }],
            ['icecandidateerror', { url: 'turn:turn.example.com:3478?transport=udp', errorCode: 401, errorText: 'Unauthorized.' }],
            ['icecandidate', { candidate: null }],
        ]);
        const denied = await testTurnServers(TURN, { RTCPeerConnection: wrong.FakePeerConnection });
        expect(denied.ok).toBe(false);
        expect(denied.errors).toEqual(['turn:turn.example.com:3478?transport=udp: the server did not accept the password (the secret here is not the one in coturn)']);

        vi.useFakeTimers();
        const silent = fakePeerConnection([]);
        const pending = testTurnServers(TURN, { RTCPeerConnection: silent.FakePeerConnection, timeout: 5000 });
        await vi.advanceTimersByTimeAsync(5000);
        const unanswered = await pending;
        expect(unanswered.ok).toBe(false);
        expect(unanswered.errors[0]).toContain('No answer from the TURN server');
        expect(silent.instances[0].closed).toBe(true);
    });

    it('needs WebRTC and a TURN server', async () => {
        expect((await testTurnServers(TURN, { RTCPeerConnection: null })).errors[0]).toContain('no WebRTC');
        expect((await testTurnServers([], { RTCPeerConnection: class {} })).errors[0]).toBe('No TURN server is set up.');
    });

    it('shows the result on the settings page', async () => {
        document.body.innerHTML = `
            <section id="turn">
                <button data-turn-browser-test data-url="/admin/settings/turn-servers">Test</button>
                <div data-turn-browser-result hidden></div>
            </section>`;
        const http = { get: vi.fn().mockResolvedValue({ data: { ice_servers: TURN } }) };
        const test = vi.fn().mockResolvedValueOnce({ ok: true, relays: ['203.0.113.7:49170'], errors: [] })
            .mockResolvedValueOnce({ ok: false, relays: [], errors: ['<b>boom</b>'] });

        const { run } = initAdminTurn(document.getElementById('turn'), { http, test });
        const output = document.querySelector('[data-turn-browser-result]');

        await run();
        expect(http.get).toHaveBeenCalledWith('/admin/settings/turn-servers');
        expect(test).toHaveBeenCalledWith(TURN);
        expect(output.hidden).toBe(false);
        expect(output.dataset.state).toBe('ok');
        expect(output.textContent).toContain('Works from this network. Relay: 203.0.113.7:49170');

        await run();
        expect(output.dataset.state).toBe('bad');
        expect(output.innerHTML).toContain('&lt;b&gt;boom&lt;/b&gt;');
        expect(document.querySelector('[data-turn-browser-test]').disabled).toBe(false);
    });
});
