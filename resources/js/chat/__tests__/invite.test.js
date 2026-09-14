// @vitest-environment happy-dom
import { describe, expect, it } from 'vitest';
import { uniqueInvitable } from '../contacts';
import { dialable, inviteLinks, inviteMessage } from '../invite';
import { inviteContactItem } from '../templates';

describe('C8 invite friends', () => {
    it('writes the invitation with the link and my username', () => {
        expect(inviteMessage({ appName: 'One2One', url: 'https://chat.example.com/register', username: 'sara' }))
            .toBe("Hi! I'm using One2One to chat and call for free. Join me: https://chat.example.com/register My username is @sara.");
    });

    it('builds WhatsApp, SMS and email links', () => {
        const links = inviteLinks('Join me & chat', '+92 300 123-4567');
        expect(links.whatsapp).toBe('https://wa.me/923001234567?text=Join%20me%20%26%20chat');
        expect(links.sms).toBe('sms:+923001234567?body=Join%20me%20%26%20chat');
        expect(links.email).toContain('mailto:?subject=');

        // A local number cannot be addressed on WhatsApp: let the person pick the chat.
        expect(inviteLinks('Hi', '0300 1234567').whatsapp).toBe('https://wa.me/?text=Hi');
        expect(inviteLinks('Hi').sms).toBe('sms:?body=Hi');
        expect(dialable(' (0300) 123 ')).toBe('0300123');
    });

    it('lists each phone contact to invite once, sorted by name', () => {
        const list = uniqueInvitable(
            [
                { name: 'Zara', phones: ['0321 5550000'] },
                { name: 'Ali', phones: ['+92 322 5551111', '0333 1'] },
                { name: 'Zara again', phones: ['+923215550000'] },
                { name: '', phones: ['0345 7778888'] },
                { name: 'Short', phones: ['112'] },
            ],
            [{ name: 'Bilal', phone: '0300 9990000' }],
        );

        expect(list.map((entry) => entry.name)).toEqual(['0345 7778888', 'Ali', 'Bilal', 'Zara']);
        expect(list.find((entry) => entry.name === 'Ali').phone).toBe('+92 322 5551111');
    });

    it('renders an Invite button for a contact who is not on the app', () => {
        const host = document.createElement('div');
        host.innerHTML = inviteContactItem({ name: 'Zara  Khan', phone: '<b>0321</b>', index: 3 });

        expect(host.querySelector('[data-invite-index]').dataset.inviteIndex).toBe('3');
        expect(host.querySelector('.avatar-fallback').textContent).toBe('ZK');
        expect(host.querySelector('b')).toBeNull();
    });
});
