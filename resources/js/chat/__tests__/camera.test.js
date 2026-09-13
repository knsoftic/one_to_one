import { describe, expect, it } from 'vitest';
import { captureName, maxRecordingSeconds, recordingFormat } from '../camera';

describe('in-app camera', () => {
    it('prefers WebM and uses MP4 only where WebM cannot be recorded (Safari)', () => {
        expect(recordingFormat(() => true)).toEqual({ mimeType: 'video/webm;codecs=vp9,opus', extension: 'webm', type: 'video/webm' });
        expect(recordingFormat((type) => type === 'video/webm')).toEqual({ mimeType: 'video/webm', extension: 'webm', type: 'video/webm' });
        expect(recordingFormat((type) => type === 'video/mp4')).toEqual({ mimeType: 'video/mp4', extension: 'mp4', type: 'video/mp4' });
        expect(recordingFormat(() => false)).toBeNull();
    });

    it('keeps recordings under the upload limit', () => {
        // 16 MB at ~1.6 Mbit/s: about 75 seconds with headroom.
        expect(maxRecordingSeconds(16 * 1024 * 1024)).toBe(75);
        expect(maxRecordingSeconds(1024)).toBe(5);
        expect(maxRecordingSeconds(1024 ** 3)).toBe(300);
    });

    it('names captures like a phone camera', () => {
        expect(captureName('IMG', 'jpg', new Date(2026, 8, 13, 14, 5, 9))).toBe('IMG_20260913_140509.jpg');
    });
});
