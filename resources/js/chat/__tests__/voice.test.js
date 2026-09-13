// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import { nextVoicePlayer, nextVoiceSpeed, speedLabel, voiceSpeed } from '../voice';

describe('voice message speed', () => {
    beforeEach(() => localStorage.clear());

    it('cycles 1× → 1.5× → 2× → 1× and ignores invalid stored values', () => {
        expect(voiceSpeed()).toBe(1);
        expect(nextVoiceSpeed(1)).toBe(1.5);
        expect(nextVoiceSpeed(1.5)).toBe(2);
        expect(nextVoiceSpeed(2)).toBe(1);
        expect(speedLabel(1.5)).toBe('1.5×');

        localStorage.setItem('chat:voice-speed', '2');
        expect(voiceSpeed()).toBe(2);
        localStorage.setItem('chat:voice-speed', '7');
        expect(voiceSpeed()).toBe(1);
    });
});

describe('nextVoicePlayer', () => {
    const row = (id, sender, voice) =>
        `<div data-message-id="${id}" data-sender-id="${sender}">${voice ? '<div data-voice-player></div>' : '<p>text</p>'}</div>`;

    it('continues with the next voice message from the same person', () => {
        document.body.innerHTML = `<div>${row(1, 7, true)}<div data-date-divider>Today</div>${row(2, 7, true)}${row(3, 8, true)}</div>`;
        const [first, second] = document.querySelectorAll('[data-voice-player]');

        expect(nextVoicePlayer(first)).toBe(second);
        expect(nextVoicePlayer(second)).toBeNull(); // different sender
    });

    it('stops at a text message', () => {
        document.body.innerHTML = `<div>${row(1, 7, true)}${row(2, 7, false)}${row(3, 7, true)}</div>`;
        expect(nextVoicePlayer(document.querySelector('[data-voice-player]'))).toBeNull();
    });
});
