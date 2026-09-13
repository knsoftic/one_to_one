import { describe, expect, it } from 'vitest';
import { adjustRect, clampRect, outputFormat, rotatedSize } from '../image-editor';

describe('photo editor geometry', () => {
    const full = { x: 0, y: 0, w: 1000, h: 800 };

    it('drags crop corners inside the image with a minimum size', () => {
        expect(adjustRect(full, 'nw', 100, 50, 1000, 800)).toEqual({ x: 100, y: 50, w: 900, h: 750 });
        expect(adjustRect(full, 'se', -300, -200, 1000, 800)).toEqual({ x: 0, y: 0, w: 700, h: 600 });
        expect(adjustRect(full, 'ne', 500, -500, 1000, 800)).toEqual({ x: 0, y: 0, w: 1000, h: 800 });
        // Cannot collapse below the minimum.
        expect(adjustRect(full, 'sw', 5000, -5000, 1000, 800, 32)).toEqual({ x: 968, y: 0, w: 32, h: 32 });
    });

    it('moves the crop without leaving the image', () => {
        const rect = { x: 100, y: 100, w: 400, h: 300 };
        expect(adjustRect(rect, 'move', 50, -20, 1000, 800)).toEqual({ x: 150, y: 80, w: 400, h: 300 });
        expect(adjustRect(rect, 'move', 900, 900, 1000, 800)).toEqual({ x: 600, y: 500, w: 400, h: 300 });
        expect(clampRect({ x: -10, y: 790, w: 5000, h: 2 }, 1000, 800)).toEqual({ x: 0, y: 768, w: 1000, h: 32 });
    });

    it('swaps width and height for quarter turns', () => {
        expect(rotatedSize(1000, 800, 1)).toEqual({ width: 800, height: 1000 });
        expect(rotatedSize(1000, 800, 2)).toEqual({ width: 1000, height: 800 });
    });

    it('keeps PNG unless it is too large, otherwise saves JPEG', () => {
        expect(outputFormat({ name: 'screen.png', type: 'image/png' })).toEqual({ type: 'image/png', name: 'screen.png' });
        expect(outputFormat({ name: 'screen.png', type: 'image/png' }, true)).toEqual({ type: 'image/jpeg', name: 'screen.jpg' });
        expect(outputFormat({ name: 'IMG_1.JPEG', type: 'image/jpeg' })).toEqual({ type: 'image/jpeg', name: 'IMG_1.jpg' });
    });
});
