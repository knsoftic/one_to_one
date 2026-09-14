// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { mentionCandidates, mentionQuery, mentionsInText } from '../mentions';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const group = {
    type: 'group',
    group: {
        members: [
            { active: true, user: { id: 1, name: 'Ayesha' } },
            { active: true, user: { id: 2, name: 'Bilal Ahmed' } },
            { active: true, user: { id: 3, name: 'Sara Malik' } },
            { active: false, user: { id: 4, name: 'Saad Left' } },
            { active: true, user: { id: 5, name: 'Hina Basit' } },
        ],
    },
};
const saved = new Map([[2, 'Bilal Bhai']]);
const nameOf = (id, name) => saved.get(Number(id)) ?? name;

describe('G4 @mentions', () => {
    it('finds the "@word" being typed', () => {
        expect(mentionQuery('Hello @Sa', 9)).toEqual({ query: 'Sa', start: 6 });
        expect(mentionQuery('@', 1)).toEqual({ query: '', start: 0 });
        expect(mentionQuery('mail me at ali@example', 22)).toBeNull();
        expect(mentionQuery('Hello @Sara done', 16)).toBeNull();
    });

    it('suggests people in the group, not me and not people who left', () => {
        expect(mentionCandidates(group, 1, '', nameOf).map((p) => p.name)).toEqual(['Bilal Bhai', 'Hina Basit', 'Sara Malik']);
        expect(mentionCandidates(group, 1, 'sa', nameOf).map((p) => p.name)).toEqual(['Sara Malik']);
        expect(mentionCandidates(group, 1, 'bas', nameOf).map((p) => p.name)).toEqual(['Hina Basit']);
        expect(mentionCandidates(group, 1, 'bhai', nameOf).map((p) => p.id)).toEqual([2]);
    });

    it('sends only mentions still in the text', () => {
        const picked = new Map([[2, { id: 2, name: 'Bilal Bhai' }], [3, { id: 3, name: 'Sara Malik' }]]);
        expect(mentionsInText('Hi @Bilal Bhai!', picked)).toEqual([{ id: 2, name: 'Bilal Bhai' }]);
    });

    it('highlights mentions in messages, with mine stronger, never inside tags', () => {
        const html = T.highlightMentions('Dinner <b>@Sara Malik</b>? <a href="https://x.test/@Sara Malik">link</a> @Ayesha', [
            { id: 3, name: 'Sara Malik' },
            { id: 1, name: 'Ayesha' },
        ]);
        expect(html).toContain('<b><span class="mention" data-mention-user="3">@Sara Malik</span></b>');
        expect(html).toContain('href="https://x.test/@Sara Malik"');
        expect(html).toContain('<span class="mention is-me" data-mention-user="1">@Ayesha</span>');
    });

    it('shows an @ badge on chats where I was mentioned', () => {
        const el = document.createElement('div');
        el.innerHTML = T.conversationItem({ id: 9, type: 'group', participant: { id: 'group-9', name: 'Family', is_group: true }, unread_count: 2, unread_mentions: 1, settings: {}, last_message: null });
        expect(el.querySelector('.badge-mention').textContent).toBe('@');
    });
});
