import { describe, expect, it } from 'vitest';
import { applyReaction, reactionOf } from '../reactions';

describe('applyReaction', () => {
    it('adds, replaces and removes one reaction per person', () => {
        let reactions = applyReaction([], 1, '👍');
        expect(reactions).toEqual([{ emoji: '👍', count: 1, user_ids: [1] }]);

        reactions = applyReaction(reactions, 2, '👍');
        expect(reactions).toEqual([{ emoji: '👍', count: 2, user_ids: [1, 2] }]);

        reactions = applyReaction(reactions, 1, '❤️');
        expect(reactions).toEqual([
            { emoji: '👍', count: 1, user_ids: [2] },
            { emoji: '❤️', count: 1, user_ids: [1] },
        ]);

        reactions = applyReaction(reactions, 2, null);
        expect(reactions).toEqual([{ emoji: '❤️', count: 1, user_ids: [1] }]);
    });

    it('does not change the original list', () => {
        const original = [{ emoji: '😂', count: 1, user_ids: [5] }];
        applyReaction(original, 5, null);
        expect(original).toEqual([{ emoji: '😂', count: 1, user_ids: [5] }]);
    });

    it('matches user ids given as strings or numbers', () => {
        const reactions = [{ emoji: '🙏', count: 1, user_ids: ['7'] }];
        expect(reactionOf(reactions, 7)).toBe('🙏');
        expect(reactionOf(reactions, 8)).toBeNull();
        expect(applyReaction(reactions, 7, null)).toEqual([]);
    });
});
