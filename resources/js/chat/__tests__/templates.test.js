// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { chatHeaderUser, contactItem, nameWithBadge } from '../templates';

describe('nameWithBadge (Y2 verified tick)', () => {
    it('adds the tick only when the payload says verified', () => {
        expect(nameWithBadge({ name: 'Ali', verified: true })).toContain('verified-badge');
        expect(nameWithBadge({ name: 'Ali', verified: true })).toContain('<svg');
        expect(nameWithBadge({ name: 'Ali', verified: false })).toBe('Ali');
        expect(nameWithBadge({ name: 'Ali' })).toBe('Ali');
        expect(nameWithBadge(null)).toBe('');
    });

    it('escapes the name and keeps the tick after it', () => {
        const out = nameWithBadge({ name: '<b>Ali</b>', verified: true }, '<b>Ali</b>');
        expect(out.startsWith('&lt;b&gt;Ali&lt;/b&gt;')).toBe(true);
        expect(out).not.toContain('<b>');
        expect(out).toContain('title="Verified"');
    });

    it('is used by the chat header and the contact rows', () => {
        const user = { id: 2, name: 'Sara', username: 'sara', initials: 'S', avatar_hue: 10, verified: true };
        expect(chatHeaderUser(user, {})).toContain('verified-badge');
        expect(chatHeaderUser({ ...user, verified: false }, {})).not.toContain('verified-badge');
        expect(contactItem({ id: 1, name: 'Sara K', user })).toContain('verified-badge');
    });
});
