// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { callLogDetail, groupCalls } from '../call-log';

const today = (hours, minutes = 0) => {
    const d = new Date();
    d.setHours(hours, minutes, 0, 0);
    return d.toISOString();
};
const call = (id, peerId, overrides = {}) => ({
    id,
    conversation_id: peerId * 10,
    peer: { id: peerId, name: `Person ${peerId}` },
    type: 'audio',
    direction: 'incoming',
    missed: false,
    duration: 65,
    end_reason: 'completed',
    created_at: today(10),
    ...overrides,
});

describe('K1 call log', () => {
    it('groups back-to-back calls with the same person, direction and outcome on one day', () => {
        const yesterday = new Date(Date.now() - 86_400_000).toISOString();
        const groups = groupCalls([
            call(9, 1, { missed: true, duration: null, end_reason: 'missed' }),
            call(8, 1, { missed: true, duration: null, end_reason: 'missed' }),
            call(7, 1),
            call(6, 2, { direction: 'outgoing' }),
            call(5, 2, { direction: 'outgoing' }),
            call(4, 2, { direction: 'outgoing', created_at: yesterday }),
        ]);

        expect(groups.map((group) => group.calls.map((c) => c.id))).toEqual([[9, 8], [7], [6, 5], [4]]);
    });

    it('describes when a call happened and how it went', () => {
        expect(callLogDetail(call(1, 1))).toMatch(/^Today, .+ · 1:05$/);
        expect(callLogDetail(call(2, 1, { direction: 'outgoing', duration: null, end_reason: 'missed' }))).toMatch(/· Not answered$/);
        expect(callLogDetail(call(3, 1, { missed: true, duration: null, end_reason: 'missed' }))).toMatch(/^Today, [^·]+$/);
    });
});
