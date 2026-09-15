/**
 * D4 — notification sounds and vibration.
 *
 * Every tone is a few short notes, drawn with Web Audio in the browser. The Android app
 * plays the same notes from res/raw/tone_*.wav, written by `npm run tones`
 * (scripts/build-tones.mjs) from this file.
 */

export const TONES = [
    { key: 'default', label: 'Default' },
    { key: 'chime', label: 'Chime' },
    { key: 'bell', label: 'Bell' },
    { key: 'pop', label: 'Pop' },
    { key: 'chirp', label: 'Chirp' },
    { key: 'marimba', label: 'Marimba' },
    { key: 'pulse', label: 'Pulse' },
    { key: 'glass', label: 'Glass' },
];

export const VIBRATIONS = [
    { key: 'default', label: 'Default' },
    { key: 'short', label: 'Short' },
    { key: 'long', label: 'Long' },
    { key: 'off', label: 'Off' },
];

/** navigator.vibrate() patterns in milliseconds (the Android app uses the same ones). */
export const VIBRATION_PATTERNS = {
    default: [180],
    short: [70],
    long: [400, 150, 400],
    off: [],
};

/**
 * Notes of each tone: frequency in Hz (`to` = slide to this frequency), start and
 * length in seconds, peak volume and wave shape.
 */
export const TONE_NOTES = {
    chime: [
        { f: 880, at: 0, len: 0.22, gain: 0.12 },
        { f: 1320, at: 0.11, len: 0.24, gain: 0.12 },
    ],
    bell: [
        { f: 1318.5, at: 0, len: 0.95, gain: 0.12 },
        { f: 2637, at: 0, len: 0.45, gain: 0.035 },
        { f: 1975.5, at: 0.015, len: 0.6, gain: 0.03 },
    ],
    pop: [
        { f: 520, to: 1040, at: 0, len: 0.09, gain: 0.16, wave: 'triangle' },
        { f: 780, to: 1300, at: 0.11, len: 0.1, gain: 0.13, wave: 'triangle' },
    ],
    chirp: [
        { f: 1600, to: 2400, at: 0, len: 0.07, gain: 0.09 },
        { f: 1800, to: 2800, at: 0.09, len: 0.07, gain: 0.09 },
        { f: 2000, to: 3200, at: 0.18, len: 0.09, gain: 0.09 },
    ],
    marimba: [
        { f: 523.25, at: 0, len: 0.28, gain: 0.16, wave: 'triangle' },
        { f: 659.25, at: 0.12, len: 0.28, gain: 0.16, wave: 'triangle' },
        { f: 783.99, at: 0.24, len: 0.42, gain: 0.16, wave: 'triangle' },
    ],
    pulse: [
        { f: 660, at: 0, len: 0.13, gain: 0.05, wave: 'square' },
        { f: 660, at: 0.19, len: 0.13, gain: 0.05, wave: 'square' },
    ],
    glass: [
        { f: 1760, at: 0, len: 0.6, gain: 0.1 },
        { f: 2217.5, at: 0.08, len: 0.7, gain: 0.07 },
        { f: 2637, at: 0.16, len: 0.8, gain: 0.05 },
    ],
};

const ATTACK = 0.015;
const SILENT = 0.0001;

/** Notes to play for a tone ("default" is the chime; unknown tones too). */
export function notesFor(tone) {
    if (tone === 'none') return [];
    return TONE_NOTES[tone] ?? TONE_NOTES.chime;
}

export function toneLabel(key) {
    if (key === 'none') return 'None';
    return TONES.find((tone) => tone.key === key)?.label ?? 'Default';
}

export function vibrationLabel(key) {
    return VIBRATIONS.find((item) => item.key === key)?.label ?? 'Default';
}

/** Play a tone with Web Audio. Returns false when nothing could be played. */
export function playTone(ctx, tone) {
    const notes = notesFor(tone);
    if (!ctx || ctx.state === 'suspended' || !notes.length) return false;

    const now = ctx.currentTime;
    notes.forEach((note) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const start = now + note.at;
        const end = start + note.len;

        osc.type = note.wave ?? 'sine';
        osc.frequency.setValueAtTime(note.f, start);
        if (note.to) osc.frequency.exponentialRampToValueAtTime(note.to, end);

        gain.gain.setValueAtTime(SILENT, start);
        gain.gain.exponentialRampToValueAtTime(note.gain ?? 0.12, start + ATTACK);
        gain.gain.exponentialRampToValueAtTime(SILENT, end);

        osc.connect(gain).connect(ctx.destination);
        osc.start(start);
        osc.stop(end + 0.03);
    });
    return true;
}

/** Vibrate the device (phones' browsers only; nothing happens elsewhere). */
export function vibrate(pattern, nav = globalThis.navigator) {
    const steps = VIBRATION_PATTERNS[pattern] ?? VIBRATION_PATTERNS.default;
    if (!steps.length || typeof nav?.vibrate !== 'function') return false;
    try {
        return nav.vibrate(steps);
    } catch {
        return false;
    }
}

/**
 * The same notes as mono samples in the range -1…1, loudest point at `peak`
 * (used to write the Android sound files).
 */
export function renderTone(tone, sampleRate = 22050, peak = 0.8) {
    const notes = notesFor(tone);
    const length = Math.max(...notes.map((note) => note.at + note.len)) + 0.05;
    const samples = new Float32Array(Math.ceil(length * sampleRate));

    const waves = {
        sine: (phase) => Math.sin(phase),
        // Band-limited like Web Audio's oscillators (odd harmonics below 9 kHz).
        square: (phase, frequency) => {
            let value = 0;
            for (let k = 1; k * frequency < 9000; k += 2) value += Math.sin(k * phase) / k;
            return (4 / Math.PI) * value;
        },
        triangle: (phase, frequency) => {
            let value = 0;
            for (let k = 1; k * frequency < 9000; k += 2) value += ((k - 1) / 2 % 2 ? -1 : 1) * Math.sin(k * phase) / (k * k);
            return (8 / (Math.PI * Math.PI)) * value;
        },
    };

    for (const note of notes) {
        const wave = waves[note.wave ?? 'sine'];
        const top = note.gain ?? 0.12;
        const first = Math.floor(note.at * sampleRate);
        const count = Math.floor(note.len * sampleRate);
        let phase = 0;

        for (let i = 0; i < count && first + i < samples.length; i++) {
            const t = i / sampleRate;
            const progress = t / note.len;
            const frequency = note.to ? note.f * (note.to / note.f) ** progress : note.f;
            phase += (2 * Math.PI * frequency) / sampleRate;

            const amplitude = t < ATTACK
                ? SILENT * (top / SILENT) ** (t / ATTACK)
                : top * (SILENT / top) ** ((t - ATTACK) / (note.len - ATTACK));
            samples[first + i] += wave(phase, frequency) * amplitude;
        }
    }

    const loudest = samples.reduce((max, value) => Math.max(max, Math.abs(value)), 0) || 1;
    for (let i = 0; i < samples.length; i++) samples[i] = (samples[i] / loudest) * peak;
    return samples;
}
