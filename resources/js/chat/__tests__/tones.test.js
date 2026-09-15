// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import { ChatTone } from '../chat-tone';
import { Notifier } from '../notifications';
import { TONE_NOTES, notesFor, playTone, renderTone, toneLabel, vibrate } from '../../lib/tones';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

/** Minimal Web Audio stand-in that records what was played. */
function fakeAudio() {
    const played = [];
    const param = () => ({ setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() });
    return {
        state: 'running',
        currentTime: 10,
        destination: {},
        played,
        createOscillator() {
            const osc = { type: '', frequency: param(), connect: (node) => node, start: (at) => played.push({ osc, at }), stop: vi.fn() };
            return osc;
        },
        createGain: () => ({ gain: param(), connect: (node) => node }),
    };
}

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('D4 tones', () => {
    it('plays the notes of a tone and nothing for none', () => {
        const ctx = fakeAudio();
        expect(playTone(ctx, 'marimba')).toBe(true);
        expect(ctx.played).toHaveLength(3);
        expect(ctx.played[0].osc.type).toBe('triangle');
        expect(ctx.played[2].at).toBeCloseTo(10.24);

        expect(playTone(ctx, 'none')).toBe(false);
        expect(playTone({ ...ctx, state: 'suspended' }, 'bell')).toBe(false);
        expect(notesFor('default')).toBe(TONE_NOTES.chime);
        expect(notesFor('mystery')).toBe(TONE_NOTES.chime);
        expect(toneLabel('none')).toBe('None');
        expect(toneLabel('glass')).toBe('Glass');
    });

    it('renders samples for the Android sound files, loudest point at the peak', () => {
        const samples = renderTone('pop', 8000, 0.8);
        const loudest = samples.reduce((max, value) => Math.max(max, Math.abs(value)), 0);
        expect(samples.length).toBe(Math.ceil((0.11 + 0.1 + 0.05) * 8000));
        expect(loudest).toBeCloseTo(0.8, 5);
        expect(samples.every(Number.isFinite)).toBe(true);
    });

    it('vibrates with the chosen pattern where the browser can', () => {
        const nav = { vibrate: vi.fn(() => true) };
        expect(vibrate('long', nav)).toBe(true);
        expect(nav.vibrate).toHaveBeenCalledWith([400, 150, 400]);
        expect(vibrate('off', nav)).toBe(false);
        expect(vibrate('short', {})).toBe(false);
    });
});

describe('D4 notifications use the chat tone', () => {
    const setup = (settings = {}, user = {}) => {
        const chat = {
            api: { has: () => false },
            config: { user: { notification_sound: true, notification_tone: 'bell', notification_vibrate: 'short', ...user } },
            conversations: new Map([[5, { id: 5, type: 'direct', settings }]]),
            active: null,
            openConversation: vi.fn(),
        };
        const notifier = new Notifier(chat);
        notifier.play = vi.fn();
        return { chat, notifier };
    };

    it('prefers the chat tone, then Settings, and stays quiet when sound is off', () => {
        const { notifier } = setup({ notification_tone: 'glass' });
        navigator.vibrate = vi.fn(() => true);

        notifier.present({ messageId: 1, conversationId: 5, title: 'Ayesha', body: 'Hi', sender: {} });
        expect(notifier.play).toHaveBeenCalledWith('glass');
        expect(notifier.alertFor(5)).toEqual({ tone: 'glass', vibrate: 'short' });

        // The server's choice (realtime notification) wins.
        notifier.present({ messageId: 2, conversationId: 5, title: 'Ayesha', body: 'Hi', sender: {}, alert: { tone: 'pop', vibrate: 'off' } });
        expect(notifier.play).toHaveBeenLastCalledWith('pop');
        expect(navigator.vibrate).toHaveBeenCalledTimes(1);
        expect(navigator.vibrate).toHaveBeenCalledWith([70]);
        delete navigator.vibrate;

        const quiet = setup({}, { notification_sound: false }).notifier;
        expect(quiet.alertFor(5).tone).toBe('none');
        expect(quiet.alertFor(99)).toEqual({ tone: 'none', vibrate: 'short' });
    });
});

describe('D4 chat tone dialog', () => {
    it('lists tones with the default from Settings and saves the choice', async () => {
        const conversation = { id: 5, type: 'direct', settings: { notification_tone: 'pop' } };
        const chat = {
            config: { user: { notification_sound: true, notification_tone: 'bell', notification_vibrate: 'long' } },
            api: { has: () => true, updateChatSettings: vi.fn(async () => ({ id: 5, settings: { notification_tone: 'chirp', notification_vibrate: 'off' } })) },
            activeConversation: () => conversation,
            upsertConversation: vi.fn(),
        };
        const tone = new ChatTone(chat);

        const items = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation, items } }));
        expect(items.join('')).toContain('Notification tone');
        expect(items.join('')).toContain('Pop');
        const none = [];
        document.dispatchEvent(new CustomEvent('chat:menu', { detail: { conversation: { id: 6, type: 'channel' }, items: none } }));
        expect(none).toHaveLength(0);

        tone.open();
        expect(document.querySelector('input[name="tone"]:checked').value).toBe('pop');
        expect(document.querySelector('.tone-option').textContent).toContain('Default (Bell)');
        expect(document.querySelector('input[name="vibrate"]:checked').value).toBe('default');
        expect(document.querySelector('.tone-segment').textContent).toContain('Default (Long)');

        document.querySelector('input[value="chirp"]').checked = true;
        document.querySelector('input[name="vibrate"][value="off"]').checked = true;
        document.querySelector('[data-tone-form]').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(chat.upsertConversation).toHaveBeenCalled());

        expect(chat.api.updateChatSettings).toHaveBeenCalledWith(5, { notification_tone: 'chirp', notification_vibrate: 'off' });
        expect(document.querySelector('.tone-dialog')).toBeNull();

        // Back to default sends null.
        tone.open();
        document.querySelector('input[name="tone"][value="default"]').checked = true;
        document.querySelector('[data-tone-form]').dispatchEvent(new Event('submit', { cancelable: true }));
        await vi.waitFor(() => expect(chat.api.updateChatSettings).toHaveBeenLastCalledWith(5, { notification_tone: null, notification_vibrate: null }));
    });
});
