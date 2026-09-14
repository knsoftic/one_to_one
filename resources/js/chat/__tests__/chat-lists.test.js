import { describe, expect, it } from 'vitest';
import { matchesFilter } from '../chat-lists';

const chats = [
    { id: 1, unread_count: 2, settings: { favorite: false } },
    { id: 2, unread_count: 0, settings: { favorite: true, marked_unread: true } },
    { id: 3, unread_count: 0, settings: {} },
];
const lists = [
    { id: 7, name: 'Family', conversation_ids: [1, 3] },
    { id: 8, name: 'Empty', conversation_ids: [] },
];
const pick = (filter) => chats.filter((c) => matchesFilter(c, filter, lists)).map((c) => c.id);

describe('C6 chat list filters', () => {
    it('shows every chat under All', () => {
        expect(pick('all')).toEqual([1, 2, 3]);
    });

    it('counts chats marked unread under Unread', () => {
        expect(pick('unread')).toEqual([1, 2]);
    });

    it('shows only favourites under Favorites', () => {
        expect(pick('favorites')).toEqual([2]);
    });

    it('shows the chats of one of my lists', () => {
        expect(pick('list:7')).toEqual([1, 3]);
        expect(pick('list:8')).toEqual([]);
        expect(pick('list:99')).toEqual([]);
    });
});
