import { describe, expect, it } from 'vitest';
import { albumLayout, albumRuns, isAlbumItem, newAlbumId } from '../album';

const photo = (id, album, extra = {}) => ({
    id,
    sender_id: 1,
    type: 'image',
    album_id: album,
    body: null,
    is_deleted: false,
    attachment: { url: `/m/${id}` },
    ...extra,
});

describe('albums', () => {
    it('groups four or more consecutive uncaptioned photos of one album', () => {
        const messages = [
            photo(1, 'a'), photo(2, 'a'), photo(3, 'a'),                        // only three: separate
            { id: 4, sender_id: 1, type: 'text', body: 'hi' },
            photo(5, 'b'), photo(6, 'b', { type: 'video' }), photo(7, 'b'), photo(8, 'b'), photo(9, 'b'), photo(10, 'b'),
            photo(11, 'b', { sender_id: 2 }),                                    // other sender ends the run
        ];

        expect(albumRuns(messages)).toEqual([{ id: 'b', start: 4, end: 9 }]);
    });

    it('captions, deletions and other albums break a run', () => {
        expect(isAlbumItem(photo(1, 'a', { body: 'Sunset' }))).toBe(false);
        expect(isAlbumItem(photo(1, 'a', { is_deleted: true }))).toBe(false);
        expect(isAlbumItem(photo(1, null))).toBe(false);

        const messages = [photo(1, 'a'), photo(2, 'a'), photo(3, 'a', { body: 'caption' }), photo(4, 'a'), photo(5, 'a')];
        expect(albumRuns(messages)).toEqual([]);
    });

    it('shows four tiles with +N until the album is opened', () => {
        const messages = [1, 2, 3, 4, 5, 6].map((id) => photo(id, 'x'));

        const closed = albumLayout(messages);
        expect([...closed.values()].map((item) => item.hidden)).toEqual([false, false, false, false, true, true]);
        expect(closed.get('4')).toMatchObject({ more: 2, last: true, position: 3, size: 6 });
        expect(closed.get('1').more).toBe(0);

        const open = albumLayout(messages, new Set(['x']));
        expect([...open.values()].some((item) => item.hidden || item.more)).toBe(false);
        expect(open.get('6').last).toBe(true);
    });

    it('creates valid v4 ids', () => {
        expect(newAlbumId()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    });
});
