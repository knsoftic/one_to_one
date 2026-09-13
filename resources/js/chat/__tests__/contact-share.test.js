// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { matchesQuery, toCard } from '../contact-share';
import { messageBubble, previewOf, setTemplateContext } from '../templates';

setTemplateContext({ meId: 1, nameOf: () => 'Friend' });

describe('contact cards', () => {
    it('cleans names and keeps up to five valid numbers', () => {
        expect(toCard({ name: '  Ayesha   Khan ', phones: ['0300 1234567', '0300 1234567', 'not a number', '+92 (42) 111-222'] }))
            .toEqual({ name: 'Ayesha Khan', phones: ['0300 1234567', '+92 (42) 111-222'] });
        expect(toCard({ name: '', phones: ['+923001234567'] })).toEqual({ name: '+923001234567', phones: ['+923001234567'] });
        expect(toCard({ name: 'Nobody', phones: [] })).toBeNull();
        expect(toCard({ name: 'Many', phones: Array.from({ length: 8 }, (_, i) => `0300123456${i}`) }).phones).toHaveLength(5);
    });

    it('searches by name or digits', () => {
        const card = { name: 'Bilal Ahmed', phones: ['+92 300 1234567'] };
        expect(matchesQuery(card, 'bil')).toBe(true);
        expect(matchesQuery(card, '1234')).toBe(true);
        expect(matchesQuery(card, '12')).toBe(false);
        expect(matchesQuery(card, 'sara')).toBe(false);
    });

    it('shows Message only for someone on the app, plus Save and Call', () => {
        const host = document.createElement('div');
        const base = { id: 9, sender_id: 2, status: 'seen', created_at: new Date().toISOString(), type: 'contact' };

        host.innerHTML = messageBubble({ ...base, contact: { name: 'Bilal <b>Ahmed</b>', phones: ['+923001234567'], user: { id: 7, username: 'bilal' }, vcard_url: '/messages/9/contact.vcf' } });
        expect(host.querySelector('.contact-card-name').textContent).toBe('Bilal <b>Ahmed</b>');
        expect(host.querySelector('[data-contact-message="7"]')).not.toBeNull();
        expect(host.querySelector('a[download]').getAttribute('href')).toBe('/messages/9/contact.vcf');
        expect(host.querySelector('a[href^="tel:"]').getAttribute('href')).toBe('tel:+923001234567');

        host.innerHTML = messageBubble({ ...base, contact: { name: 'Sara', phones: ['0300'], user: null, vcard_url: '/v' } });
        expect(host.querySelector('[data-contact-message]')).toBeNull();

        expect(previewOf({ type: 'contact', contact: { name: 'Sara' } })).toBe('👤 Contact: Sara');
    });
});
