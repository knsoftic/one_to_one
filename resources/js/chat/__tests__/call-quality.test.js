// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: {} })) } }));

import { CallManager, rateConnection, readCallStats, smoothQuality } from '../calls';

function connectedCall() {
    const chat = {
        me: { id: 1 },
        config: { calls: { enabled: true } },
        api: { has: () => true, url: (name, id) => `/${name}/${id ?? ''}` },
        activeConversation: () => null,
    };
    const manager = new CallManager(chat);
    const encodings = { video: [{ active: true }], audio: [{ active: true }] };
    const sender = (kind) => ({
        getParameters: () => ({ encodings: encodings[kind].map((e) => ({ ...e })) }),
        setParameters: vi.fn(async (params) => {
            encodings[kind] = params.encodings;
        }),
    });
    const transceivers = [{ receiver: { track: { kind: 'audio' } }, sender: sender('audio') }, { receiver: { track: { kind: 'video' } }, sender: sender('video') }];
    const session = manager.createSession({ call: { id: 4, type: 'video', caller_id: 1 }, role: 'caller', peer: { id: 2, name: 'Sara' } });
    session.status = 'connected';
    session.pc = { getTransceivers: () => transceivers };
    manager.session = session;
    return { manager, session, encodings };
}

afterEach(() => {
    document.body.innerHTML = '';
    localStorage.clear();
});

describe('K5 call quality', () => {
    it('reads round-trip time, packet loss and jitter from WebRTC stats', () => {
        const first = readCallStats([
            { type: 'candidate-pair', state: 'succeeded', nominated: true, currentRoundTripTime: 0.12 },
            { type: 'inbound-rtp', kind: 'audio', packetsLost: 10, packetsReceived: 1000, jitter: 0.01 },
            { type: 'inbound-rtp', kind: 'video', packetsLost: 500, packetsReceived: 100 },
        ]);
        // The first sample has nothing to compare the counters with.
        expect(first).toEqual({ rtt: 0.12, jitter: 0.01, loss: 0, counters: { lost: 10, received: 1000 } });

        const second = readCallStats([
            { type: 'candidate-pair', state: 'succeeded', nominated: true, currentRoundTripTime: 0.2 },
            { type: 'remote-inbound-rtp', kind: 'video', fractionLost: 0.02 },
            { type: 'inbound-rtp', kind: 'audio', packetsLost: 22, packetsReceived: 1088, jitter: 0.02 },
        ], first.counters);
        expect(second.loss).toBeCloseTo(12 / 100);
        expect(second.rtt).toBe(0.2);
    });

    it('rates the connection and ignores a single bad sample', () => {
        expect(rateConnection({ rtt: 0.1, loss: 0.01, jitter: 0.01 })).toBe('good');
        expect(rateConnection({ rtt: 0.35, loss: 0, jitter: 0 })).toBe('weak');
        expect(rateConnection({ rtt: 0.1, loss: 0.15, jitter: 0 })).toBe('poor');

        expect(smoothQuality(['good', 'poor'])).toBe('good');
        expect(smoothQuality(['poor', 'poor'])).toBe('poor');
        expect(smoothQuality(['weak', 'poor'])).toBe('weak');
        expect(smoothQuality(['poor', 'good'])).toBe('good');
    });

    it('shows "Poor connection" and turns on low data mode from it', async () => {
        const { manager, session, encodings } = connectedCall();
        const pill = manager.el.quality;

        manager.render();
        expect(pill.hidden).toBe(true);

        session.quality = 'poor';
        manager.render();
        expect(pill.hidden).toBe(false);
        expect(pill.textContent).toContain('Poor connection');
        expect(pill.textContent).toContain('Use less data');

        pill.click();
        await vi.waitFor(() => expect(encodings.video[0].maxBitrate).toBe(150000));
        expect(encodings.video[0]).toMatchObject({ scaleResolutionDownBy: 2, maxFramerate: 15 });
        expect(encodings.audio[0].maxBitrate).toBe(24000);
        expect(localStorage.getItem('calls:low-data')).toBe('1');
        expect(pill.textContent).toContain('Low data mode');

        // Turning it off lifts the limits; the choice is remembered for the next call.
        await manager.toggleLowData();
        expect(encodings.video[0].maxBitrate).toBeUndefined();
        expect(encodings.video[0].scaleResolutionDownBy).toBe(1);
        expect(localStorage.getItem('calls:low-data')).toBe('0');
    });

    it("follows the other person's low data mode too", async () => {
        const { manager, session, encodings } = connectedCall();

        await manager.processSignal(session, { id: 3, type: 'media', payload: JSON.stringify({ muted: false, cameraOff: false, lowData: true }) });
        await vi.waitFor(() => expect(encodings.video[0].maxBitrate).toBe(150000));
        expect(session.remoteLowData).toBe(true);
    });
});
