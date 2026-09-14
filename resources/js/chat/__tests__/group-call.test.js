// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: {} })) } }));

import axios from '../../bootstrap';
import { CallManager } from '../calls';
import { meshPlan } from '../group-call';
import { callablePeople } from '../people-picker';

const person = (id, name) => ({ id, name, initials: name[0], avatar_hue: id * 40 });

const room = (overrides = {}) => ({
    id: 9,
    type: 'audio',
    status: 'active',
    max_participants: 4,
    participants: [
        { user_id: 1, user: person(1, 'Ayesha'), status: 'joined', client_id: 'ayesha-tab', join_seq: 1, call_id: 5 },
        { user_id: 2, user: person(2, 'Bilal'), status: 'joined', client_id: 'bilal-tab', join_seq: 2, call_id: 5 },
        { user_id: 3, user: person(3, 'Sara'), status: 'ringing', client_id: null, join_seq: null, call_id: 6, invited_by: 2 },
    ],
    ...overrides,
});

function manager(meId = 1, clientId = 'ayesha-tab') {
    const chat = {
        me: person(meId, 'Me'),
        config: { calls: { enabled: true, maxGroupParticipants: 4 } },
        api: { has: () => true, url: (name, id) => `/${name}/${id ?? ''}` },
        activeConversation: () => null,
        decorate: (user) => user,
    };
    const calls = new CallManager(chat);
    calls.clientId = clientId;
    calls.group.clientId = clientId;
    return calls;
}

afterEach(() => {
    document.body.innerHTML = '';
    document.documentElement.className = '';
    axios.post.mockClear();
});

describe('K6 group calls', () => {
    it('connects each device to everyone who joined; the later joiner sends the offer', () => {
        const plan = meshPlan(room(), 2, 'bilal-tab');
        expect(plan).toEqual([{ key: '1:ayesha-tab', participant: expect.objectContaining({ user_id: 1 }), offerer: true }]);

        expect(meshPlan(room(), 1, 'ayesha-tab')[0].offerer).toBe(false);
        // Another tab of the same person, or a room that ended, connects to nobody.
        expect(meshPlan(room(), 1, 'other-tab')).toEqual([]);
        expect(meshPlan(room({ status: 'ended' }), 1, 'ayesha-tab')).toEqual([]);
    });

    it('lists people to add once, without me, blocked chats or people already in the call', () => {
        const chat = {
            me: { id: 1 },
            contactsPanel: { contacts: [{ name: 'Bilal Bhai', user: person(2, 'Bilal') }, { name: 'Sara', user: person(3, 'Sara') }] },
            sortedConversations: () => [
                { participant: person(2, 'Bilal') },
                { participant: person(4, 'Hina') },
                { participant: person(5, 'Blocked'), blocked_by_me: true },
                { participant: person(1, 'Me'), is_self: true },
            ],
            participantOf: (c) => c.participant,
        };

        expect(callablePeople(chat, [3]).map((p) => p.name)).toEqual(['Bilal Bhai', 'Hina']);
    });

    it('shows a tile for me, everyone in the call and people still ringing', () => {
        const calls = manager();
        const group = calls.group;
        window.RTCPeerConnection = class {
            constructor() { this.connectionState = 'new'; this.signalingState = 'stable'; }
            addTrack() {}
            addTransceiver() {}
            getTransceivers() { return []; }
            close() {}
        };

        group.begin({ room: room(), localStream: null, type: 'audio', callId: 5 });

        const names = [...group.el.grid.querySelectorAll('.group-tile-name')].map((el) => el.textContent);
        expect(names).toEqual(['You', 'Bilal · Connecting…', 'Sara · Ringing…']);
        expect(group.el.grid.dataset.count).toBe('3');
        expect(calls.busy).toBe(true);
        expect(document.documentElement.classList.contains('has-call-screen')).toBe(true);

        // Signals for the room go to the group call, hanging up leaves it.
        const onSignal = vi.spyOn(group, 'onSignal');
        calls.onSignal({ id: 1, call_room_id: 9, type: 'candidate', payload: '{}' });
        expect(onSignal).toHaveBeenCalled();

        calls.hangUp();
        expect(axios.post).toHaveBeenCalledWith('/callRoomLeave/9', {});
        expect(group.active.status).toBe('ended');
        delete window.RTCPeerConnection;
    });

    it('moves a one-to-one call into the group call when someone is added', () => {
        const calls = manager();
        window.RTCPeerConnection = class {
            constructor() { this.connectionState = 'new'; }
            addTrack() {}
            addTransceiver() {}
            getTransceivers() { return []; }
            close() {}
        };
        const pc = { close: vi.fn() };
        const session = calls.createSession({ call: { id: 5, type: 'audio', caller_id: 1, conversation_id: 12 }, role: 'caller', peer: person(2, 'Bilal') });
        session.status = 'connected';
        session.pc = pc;
        calls.session = session;
        for (const media of [calls.el.remoteVideo, calls.el.remoteAudio, calls.el.localVideo, calls.el.pipVideo]) {
            Object.defineProperty(media, 'srcObject', { writable: true, value: null });
        }

        // The room update reaches the two people who were already talking.
        calls.group.onRoomUpdated(room());

        expect(pc.close).toHaveBeenCalled();
        expect(calls.session).toBeNull();
        expect(calls.group.active.callId).toBe(5);
        expect(calls.group.active.conversationId).toBe(12);
        // The call itself was not ended on the server.
        expect(axios.post.mock.calls.some(([url]) => url.startsWith('/callEnd'))).toBe(false);
        delete window.RTCPeerConnection;
    });

    it('rings an invite to a group call with the names already talking', async () => {
        const calls = manager(3, 'sara-phone');
        calls.enabled = true;
        calls.post = vi.fn(async () => ({}));
        calls.serverNow = () => Date.now();

        await calls.onIncoming({
            id: 6,
            status: 'ringing',
            type: 'video',
            callee_id: 3,
            caller_id: 2,
            created_at: new Date().toISOString(),
            caller: person(2, 'Bilal'),
            call_room_id: 9,
            room: { id: 9, participants: [{ user_id: 1, name: 'Ayesha' }, { user_id: 2, name: 'Bilal' }] },
        }, []);

        expect(calls.el.status.textContent).toBe('Group video call with Ayesha');
        calls.dismissIncoming();
    });
});
