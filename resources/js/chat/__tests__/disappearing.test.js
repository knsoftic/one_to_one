// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { expiredIds, timerLabel } from '../disappearing';
import { messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

describe('disappearing messages', () => {
    it('labels timers and finds expired messages', () => {
        expect(timerLabel(604800)).toBe('7 days');
        expect(timerLabel(null)).toBe('Off');

        const now = Date.parse('2026-09-13T12:00:00Z');
        expect(expiredIds([
            { id: 1, expires_at: '2026-09-13T11:59:59Z' },
            { id: 2, expires_at: '2026-09-14T12:00:00Z' },
            { id: 3, expires_at: null },
        ], now)).toEqual([1]);
    });

    it('shows notices without a bubble and a timer on expiring messages', () => {
        const host = document.createElement('div');
        const notice = { id: 5, sender_id: 2, type: 'system', created_at: '2026-09-13T10:00:00Z', system: { event: 'disappearing', seconds: 86400, text: '⏱️ Disappearing messages turned on: new messages disappear after 24 hours' } };

        host.innerHTML = messageBubble(notice);
        expect(host.querySelector('.message.is-system .system-notice').textContent).toContain('24 hours');
        expect(host.querySelector('.message-bubble')).toBeNull();
        expect(previewOf(notice)).toContain('Disappearing messages turned on');

        host.innerHTML = messageBubble({ id: 6, sender_id: 2, type: 'text', body: 'hi', status: 'seen', created_at: '2026-09-13T10:00:00Z', expires_at: '2026-09-14T10:00:00Z' });
        expect(host.querySelector('.message-timer')).not.toBeNull();
    });
});
