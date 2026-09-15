// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { businessSection, labelDots, labelsFor, openLabel, QuickReplies, quickReplyMatches, quickReplyQuery, safeWebsite } from '../business';
import * as T from '../templates';

T.setTemplateContext({ meId: 1, nameOf: () => '' });

const replies = [
    { id: 1, shortcut: 'thanks', message: 'Thank you for your order!' },
    { id: 2, shortcut: 'hours', message: 'We are open 9 to 5.' },
    { id: 3, shortcut: 'delivery', message: 'Delivery takes 2 days. Thanks for waiting.' },
    { id: 4, shortcut: 'pay-thanks', message: 'Payment received.' },
];

describe('X8 quick replies', () => {
    it('finds the "/shortcut" being typed, but not in links', () => {
        expect(quickReplyQuery('/th', 3)).toEqual({ query: 'th', start: 0 });
        expect(quickReplyQuery('Hi /Hours', 9)).toEqual({ query: 'hours', start: 3 });
        expect(quickReplyQuery('/', 1)).toEqual({ query: '', start: 0 });
        expect(quickReplyQuery('see https://example.com/', 24)).toBeNull();
        expect(quickReplyQuery('1/2', 3)).toBeNull();
        expect(quickReplyQuery('/thanks done', 12)).toBeNull();
    });

    it('lists shortcuts starting with the query first, then other matches', () => {
        expect(quickReplyMatches(replies, '').map((r) => r.shortcut)).toEqual(['delivery', 'hours', 'pay-thanks', 'thanks']);
        expect(quickReplyMatches(replies, 'th').map((r) => r.shortcut)).toEqual(['thanks', 'pay-thanks', 'delivery']);
        expect(quickReplyMatches(replies, 'zzz')).toEqual([]);
    });

    it('replaces "/shortcut" with the saved message', async () => {
        document.body.innerHTML = '<div><form id="f"><textarea id="t"></textarea></form></div>';
        const input = document.getElementById('t');
        const chat = {
            config: { business: { enabled: true }, routes: { settings: '/settings' } },
            api: { has: () => true, quickReplies: vi.fn().mockResolvedValue({ data: replies }) },
            el: { composerInput: input, composerForm: document.getElementById('f') },
            activeConversation: () => ({ id: 5 }),
        };
        const quick = new QuickReplies(chat);

        input.value = 'Salam /than';
        input.setSelectionRange(11, 11);
        await quick.update();
        expect(quick.popup.hidden).toBe(false);
        expect(quick.popup.querySelectorAll('[data-quick-reply]')).toHaveLength(3);
        expect(quick.popup.querySelector('.quick-reply-manage').getAttribute('href')).toBe('/settings?tab=business');

        input.form.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));
        expect(input.value).toBe('Salam Thank you for your order!');
        expect(quick.popup.hidden).toBe(true);
        expect(chat.api.quickReplies).toHaveBeenCalledTimes(1);
    });

    it('stays off for normal accounts', () => {
        document.body.innerHTML = '<div><form id="f"><textarea id="t"></textarea></form></div>';
        const quick = new QuickReplies({
            config: { business: { enabled: false } },
            api: { has: () => true },
            el: { composerInput: document.getElementById('t'), composerForm: document.getElementById('f') },
        });
        expect(quick.popup).toBeUndefined();
        expect(document.querySelector('.quick-reply-popup')).toBeNull();
    });
});

describe('X8 labels', () => {
    const lists = [
        { id: 1, name: 'Paid', color: 'green', conversation_ids: [5, 6] },
        { id: 2, name: 'Family', color: null, conversation_ids: [5] },
        { id: 3, name: 'New <order>', color: 'amber', conversation_ids: [5] },
        { id: 4, name: 'Odd', color: 'url(x)', conversation_ids: [5] },
    ];

    it('shows only coloured lists as labels', () => {
        expect(labelsFor(5, lists).map((l) => l.id)).toEqual([1, 3]);
        expect(labelsFor(7, lists)).toEqual([]);
    });

    it('draws escaped label dots on the chat row', () => {
        const dots = labelDots(labelsFor(5, lists));
        expect(dots).toContain('label-dot is-green');
        expect(dots).toContain('New &lt;order&gt;');
        expect(labelDots([])).toBe('');

        const row = T.conversationItem({ id: 5, participant: { id: 2, name: 'Ali' } }, { labels: labelsFor(5, lists) });
        expect(row).toContain('label-dot is-amber');
    });
});

describe('X8 business details', () => {
    it('only links http(s) websites', () => {
        expect(safeWebsite('https://example.com')).toBe('https://example.com/');
        expect(safeWebsite('javascript:alert(1)')).toBeNull();
        expect(safeWebsite('not a url')).toBeNull();
    });

    it('says whether the business is open', () => {
        expect(openLabel({ hours_mode: 'always' }).text).toBe('Open 24 hours');
        expect(openLabel({ hours_mode: 'custom', open_now: false })).toEqual({ text: 'Closed now', state: 'closed' });
        expect(openLabel({ hours_mode: 'custom', open_now: null })).toBeNull();
    });

    it('renders the contact info section safely', () => {
        const section = businessSection({
            category_label: 'Shop',
            description: '<b>Best</b> shoes',
            address: 'Mall Road',
            email: 'shop@example.com',
            website: 'javascript:alert(1)',
            hours_mode: 'custom',
            open_now: true,
            hours: [{ day: 'Monday', open: true, from: '09:00', to: '17:00' }, { day: 'Sunday', open: false, from: '09:00', to: '17:00' }],
        });
        expect(section).toContain('Business account · Shop');
        expect(section).toContain('&lt;b&gt;Best&lt;/b&gt;');
        expect(section).toContain('Open now');
        expect(section).toContain('09:00 – 17:00');
        expect(section).toContain('Closed');
        expect(section).toContain('mailto:shop@example.com');
        expect(section).not.toContain('javascript:');
        expect(businessSection(null)).toBe('');
    });

    it('marks automatic replies in the bubble', () => {
        expect(T.messageBubble({ id: 1, type: 'text', body: 'Closed now', auto_reply: 'away', is_mine: false, created_at: new Date().toISOString() })).toContain('Away message');
    });
});

afterEach(() => {
    document.body.innerHTML = '';
});
