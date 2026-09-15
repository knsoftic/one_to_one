/**
 * Writes the notification sounds of the Android app (D4):
 * mobile/android/app/src/main/res/raw/tone_<name>.wav, from resources/js/lib/tones.js,
 * so the phone plays the same tones as the web app.
 *
 * Usage: npm run tones
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { TONE_NOTES, renderTone } from '../resources/js/lib/tones.js';

const SAMPLE_RATE = 22050;
const target = resolve(dirname(fileURLToPath(import.meta.url)), '../mobile/android/app/src/main/res/raw');
mkdirSync(target, { recursive: true });

/** 16-bit mono PCM WAV. */
function wav(samples) {
    const data = Buffer.alloc(samples.length * 2);
    samples.forEach((value, i) => data.writeInt16LE(Math.round(Math.max(-1, Math.min(1, value)) * 32767), i * 2));

    const header = Buffer.alloc(44);
    header.write('RIFF', 0);
    header.writeUInt32LE(36 + data.length, 4);
    header.write('WAVE', 8);
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16);
    header.writeUInt16LE(1, 20);
    header.writeUInt16LE(1, 22);
    header.writeUInt32LE(SAMPLE_RATE, 24);
    header.writeUInt32LE(SAMPLE_RATE * 2, 28);
    header.writeUInt16LE(2, 32);
    header.writeUInt16LE(16, 34);
    header.write('data', 36);
    header.writeUInt32LE(data.length, 40);

    return Buffer.concat([header, data]);
}

for (const tone of Object.keys(TONE_NOTES)) {
    const file = resolve(target, `tone_${tone}.wav`);
    writeFileSync(file, wav(renderTone(tone, SAMPLE_RATE)));
    console.log(`Wrote ${file}`);
}
