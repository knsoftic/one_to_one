// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn(), patch: vi.fn() } }));

import axios from '../../bootstrap';
import { ContactInfo } from '../contact-info';
import { formatLastSeen } from '../format';
import * as T from '../templates';
import { initSettings } from '../../ui/settings';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const conversation = (participant, extra = {}) => ({ id: 5, type: 'direct', participant: { id: 2, name: 'Ayesha Khan', username: 'ayesha', initials: 'AK', avatar_hue: 10, ...participant }, ...extra });

function setup(conv) {
    const headerUser = document.createElement('div');
    document.body.appendChild(headerUser);
    const chat = {
        el: { headerUser },
        api: { has: (name) => name === 'reportUser' },
        active: { id: conv.id },
        conversations: new Map([[conv.id, conv]]),
        activeConversation: () => conv,
        participantOf: (c) => ({ ...c.participant, name: 'Ayesha (office)' }),
        refreshConversation: vi.fn(async () => conv),
    };
    return { chat, info: new ContactInfo(chat) };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Phase 6 contact info', () => {
    it('shows About, presence and actions; hidden details stay out', async () => {
        const conv = conversation({ about: 'At work 💼', is_online: true });
        const { chat, info } = setup(conv);
        const actions = [];
        document.addEventListener('chat:action', (event) => actions.push(event.detail.action));

        chat.el.headerUser.click();
        await vi.waitFor(() => expect(chat.refreshConversation).toHaveBeenCalledWith(5));

        expect(document.querySelector('.group-info-name').textContent).toBe('Ayesha (office)');
        expect(document.querySelector('.group-info-count').textContent).toBe('@ayesha · ~Ayesha Khan');
        expect(document.querySelector('.group-info-description').textContent).toBe('At work 💼');
        expect(document.querySelector('.contact-info-presence').textContent).toBe('online');

        document.querySelector('[data-contact-action="report-user"]').click();
        expect(actions).toEqual(['report-user']);
        expect(document.querySelector('.contact-info')).toBeNull();

        // No About and no last seen shared: nothing is shown for them.
        conv.participant = { ...conv.participant, about: null, is_online: false, last_seen: null };
        await info.open(5);
        expect(document.querySelector('.group-info-description')).toBeNull();
        expect(document.querySelector('.contact-info-presence')).toBeNull();
        expect(formatLastSeen(null)).toBe('');
    });

    it('is only for one-to-one chats', async () => {
        const { chat, info } = setup(conversation({}, { type: 'group' }));
        chat.el.headerUser.click();
        expect(document.querySelector('.contact-info')).toBeNull();
        expect(info.isContact({ type: 'direct', is_self: true, participant: {} })).toBe(false);
    });
});

describe('Phase 6 privacy settings', () => {
    it('saves a privacy choice and puts it back when saving fails', async () => {
        document.body.innerHTML = `
            <div data-settings-tabs>
                <select data-preference="last_seen_privacy"><option value="everyone" selected>Everyone</option><option value="contacts">My contacts</option></select>
                <input type="checkbox" data-preference="read_receipts" checked>
            </div>`;
        const config = { routes: { preferences: '/settings/preferences' }, user: {} };
        initSettings(config);

        axios.patch.mockResolvedValueOnce({ data: {} });
        const select = document.querySelector('select');
        select.value = 'contacts';
        select.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(axios.patch).toHaveBeenCalledWith('/settings/preferences', { last_seen_privacy: 'contacts' }));
        await vi.waitFor(() => expect(config.user.last_seen_privacy).toBe('contacts'));

        axios.patch.mockRejectedValueOnce(new Error('offline'));
        select.value = 'everyone';
        select.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(select.value).toBe('contacts'));

        axios.patch.mockResolvedValueOnce({ data: {} });
        const receipts = document.querySelector('input');
        receipts.checked = false;
        receipts.dispatchEvent(new Event('change'));
        await vi.waitFor(() => expect(axios.patch).toHaveBeenLastCalledWith('/settings/preferences', { read_receipts: false }));
    });
});
