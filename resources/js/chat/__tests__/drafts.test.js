import { describe, expect, it, vi } from 'vitest';
import { DraftStore } from '../drafts';

function memoryStorage() {
    const data = new Map();
    return {
        getItem: (key) => (data.has(key) ? data.get(key) : null),
        setItem: (key, value) => data.set(key, String(value)),
        dump: () => data,
    };
}

describe('DraftStore', () => {
    it('saves, restores and removes drafts per chat and per account', () => {
        const storage = memoryStorage();
        const drafts = new DraftStore(1, storage);

        expect(drafts.set(10, 'See you at')).toBe(true);
        expect(drafts.set(10, 'See you at')).toBe(false);
        expect(new DraftStore(1, storage).get(10)).toBe('See you at');
        expect(new DraftStore(2, storage).get(10)).toBe('');

        expect(drafts.set(10, '   ')).toBe(true);
        expect(new DraftStore(1, storage).get(10)).toBe('');
    });

    it('forgets drafts older than 30 days and survives broken storage', () => {
        const storage = memoryStorage();
        vi.useFakeTimers();
        new DraftStore(1, storage).set(5, 'old');
        vi.advanceTimersByTime(31 * 24 * 60 * 60 * 1000);
        expect(new DraftStore(1, storage).get(5)).toBe('');
        vi.useRealTimers();

        storage.setItem('chat:drafts:1', '{not json');
        expect(new DraftStore(1, storage).get(5)).toBe('');
        expect(new DraftStore(1, null).set(5, 'no storage')).toBe(true);
    });
});
