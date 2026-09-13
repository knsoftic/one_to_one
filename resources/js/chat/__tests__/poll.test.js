// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { applyVote, cleanOptions, nextSelection } from '../poll';
import { messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Ayesha' });

const poll = {
    question: 'Dinner?',
    multiple: false,
    total_voters: 1,
    options: [
        { id: 1, text: 'Biryani', count: 1, voter_ids: [2] },
        { id: 2, text: 'Pizza', count: 0, voter_ids: [] },
    ],
};

describe('polls', () => {
    it('toggles one or several answers', () => {
        expect(nextSelection([], 2, false)).toEqual([2]);
        expect(nextSelection([2], 1, false)).toEqual([1]);
        expect(nextSelection([1], 1, false)).toEqual([]);
        expect(nextSelection([1], 3, true)).toEqual([1, 3]);
        expect(nextSelection([1, 3], 1, true)).toEqual([3]);
    });

    it('updates counts right away when voting', () => {
        const voted = applyVote(poll, 1, [1]);
        expect(voted.options[0]).toMatchObject({ count: 2, voter_ids: [2, 1] });
        expect(voted.total_voters).toBe(2);

        const moved = applyVote(voted, 2, [2]);
        expect(moved.options.map((o) => o.count)).toEqual([1, 1]);
        expect(applyVote(moved, 1, []).total_voters).toBe(1);
    });

    it('cleans typed options', () => {
        expect(cleanOptions([' Yes ', 'yes', '', 'No  way', null])).toEqual(['Yes', 'No way']);
    });

    it('renders options with my answer, bars and totals', () => {
        const host = document.createElement('div');
        host.innerHTML = messageBubble({ id: 4, sender_id: 2, status: 'seen', created_at: new Date().toISOString(), type: 'poll', poll: applyVote(poll, 1, [1]) });

        const [first, second] = host.querySelectorAll('[data-poll-option]');
        expect(host.querySelector('[data-poll="4"] .poll-question').textContent).toBe('Dinner?');
        expect(first.getAttribute('aria-checked')).toBe('true');
        expect(first.getAttribute('title')).toBe('Ayesha, You');
        expect(first.querySelector('.poll-bar span').style.width).toBe('100%');
        expect(second.getAttribute('role')).toBe('radio');
        expect(host.querySelector('.poll-total').textContent).toBe('2 votes');
        expect(previewOf({ type: 'poll', poll })).toBe('📊 Poll: Dinner?');
    });
});
