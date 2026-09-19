// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from '../../bootstrap';
import { toast } from '../../lib/toast';
import { AdsManager } from '../ads';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: { ad: null } })) } }));
vi.mock('../../lib/native', () => ({ isNativeApp: () => false }));
vi.mock('../../lib/toast', () => ({ toast: vi.fn() }));

const AD = { title: 'Buy now', body: 'Cheap', cta: 'Shop', sponsor: 'ACME', image: 'https://x/y.jpg', click: '/ads/1/go', format: 'row' };

const PLACEMENTS = {
    chat_list: { format: 'row', container: '[data-conversation-list]', item: '.conversation-item' },
    calls: { format: 'row', container: '[data-calls-list]', item: '.calls-entry' },
};

function chatWith(ads, { chats = 8, calls = 6 } = {}) {
    document.body.innerHTML = `
        <div data-conversation-list>${'<button class="conversation-item"></button>'.repeat(chats)}</div>
        <div data-calls-list>${'<button class="calls-entry"></button>'.repeat(calls)}</div>`;
    return {
        config: { ads, routes: { adsNext: '/ads/next', adsOpen: '/ads/open' } },
        listMode: 'all',
        el: { conversationList: document.querySelector('[data-conversation-list]') },
    };
}

/** Let the constructor's awaits and the requestAnimationFrame callbacks run. */
const settle = async () => {
    for (let i = 0; i < 4; i++) await Promise.resolve();
    await new Promise((resolve) => requestAnimationFrame(resolve));
};

describe('Ads in the app', () => {
    beforeEach(() => {
        axios.get.mockResolvedValue({ data: { ad: null } });
        axios.post.mockResolvedValue({ data: {} });
    });
    afterEach(() => {
        document.body.innerHTML = '';
        vi.clearAllMocks();
    });

    it('does nothing when the admin has ads switched off', () => {
        new AdsManager(chatWith({ enabled: false }));
        expect(axios.get).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('reports the device on open, without ever asking the user anything', async () => {
        new AdsManager(chatWith({ enabled: true, placements: {}, every: 6 }));
        await settle();

        expect(axios.post).toHaveBeenCalledWith('/ads/open', expect.objectContaining({ platform: 'web', location_allowed: false }));
    });

    it('asks for one ad per switched-on placement and slots each into its own screen', async () => {
        axios.get.mockResolvedValue({ data: { ad: AD } });
        new AdsManager(chatWith({ enabled: true, placements: PLACEMENTS, every: 6 }));
        await settle();

        expect(axios.get).toHaveBeenCalledWith('/ads/next', { params: { placement: 'chat_list' } });
        expect(axios.get).toHaveBeenCalledWith('/ads/next', { params: { placement: 'calls' } });

        const chats = document.querySelector('[data-conversation-list]');
        const calls = document.querySelector('[data-calls-list]');
        expect(chats.querySelectorAll('.ad-slot')).toHaveLength(1);
        expect(calls.querySelectorAll('.ad-slot')).toHaveLength(1);
        // After the 6th row in the chats list, and after the last row in the shorter calls list.
        expect([...chats.children].indexOf(chats.querySelector('.ad-slot'))).toBe(6);
        expect([...calls.children].indexOf(calls.querySelector('.ad-slot'))).toBe(6);
        expect(chats.querySelector('.ad-card-title').textContent).toBe('Buy now');
        expect(chats.querySelector('.ad-card').getAttribute('href')).toBe('/ads/1/go');
    });

    it('moves the card rather than duplicating it when a list redraws', async () => {
        axios.get.mockResolvedValue({ data: { ad: AD } });
        const chat = chatWith({ enabled: true, placements: { chat_list: PLACEMENTS.chat_list }, every: 6 });
        new AdsManager(chat);
        await settle();

        document.dispatchEvent(new CustomEvent('chat:conversations-rendered'));
        await settle();

        expect(document.querySelectorAll('[data-conversation-list] .ad-slot')).toHaveLength(1);
    });

    it('keeps the archived and locked folders free of ads', async () => {
        axios.get.mockResolvedValue({ data: { ad: AD } });
        const chat = chatWith({ enabled: true, placements: { chat_list: PLACEMENTS.chat_list }, every: 6 });
        const ads = new AdsManager(chat);
        await settle();
        expect(document.querySelector('.ad-slot')).not.toBeNull();

        chat.listMode = 'archived';
        ads.place('chat_list');
        expect(document.querySelector('.ad-slot')).toBeNull();
    });

    /** The Status tab placement, on its own container so instances from earlier tests never touch it. */
    function statusChat() {
        document.body.innerHTML = `<div data-status-body>${'<button class="status-row"></button>'.repeat(8)}</div>`;
        return {
            config: {
                ads: { enabled: true, placements: { status_list: { format: 'row', container: '[data-status-body]', item: '.status-row' } }, every: 6 },
                routes: { adsNext: '/ads/next', adsOpen: '/ads/open', adsTap: '/ads/__ID__/tap' },
            },
            listMode: 'all',
            el: {},
        };
    }

    it('tags a promotion "Promoted · name" and opens an internal one in the app after posting the tap', async () => {
        const promoted = { ...AD, id: 12, title: 'Cricket', sponsor: 'Ali', promoted: true, internal: true, kind: 'channel', click: '/ads/12/go', url: '/channels/abc' };
        axios.get.mockResolvedValue({ data: { ad: promoted } });
        axios.post.mockImplementation(async (url) => (url === '/ads/12/tap' ? { data: { open: { type: 'channel', id: 9 }, url: '/channels/abc' } } : { data: {} }));
        const chat = statusChat();
        chat.channels = { preview: vi.fn() };
        new AdsManager(chat);
        await settle();

        const card = document.querySelector('[data-status-body] .ad-card');
        expect(card.querySelector('.ad-card-tag').textContent).toBe('Promoted · Ali');
        expect(card.classList.contains('is-promoted')).toBe(true);
        // The link stays as the no-JS fallback, but it is handled in the app: no new tab.
        expect(card.getAttribute('href')).toBe('/ads/12/go');
        expect(card.hasAttribute('target')).toBe(false);
        expect(card.hasAttribute('data-ad-internal')).toBe(true);

        const click = new MouseEvent('click', { bubbles: true, cancelable: true });
        card.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
        await settle();

        expect(axios.post).toHaveBeenCalledWith('/ads/12/tap', { placement: 'status_list' });
        expect(chat.channels.preview).toHaveBeenCalledWith(9);
    });

    it('keeps external cards on a new tab and opens a status through the viewer', async () => {
        axios.get.mockResolvedValue({ data: { ad: { ...AD, promoted: true, sponsor: 'Shop', internal: false, kind: 'link' } } });
        new AdsManager(statusChat());
        await settle();

        const card = document.querySelector('[data-status-body] .ad-card');
        expect(card.getAttribute('target')).toBe('_blank');
        expect(card.getAttribute('rel')).toBe('noopener nofollow sponsored');
        expect(card.querySelector('.ad-card-tag').textContent).toBe('Promoted · Shop');
        expect(card.hasAttribute('data-ad-internal')).toBe(false);

        // A promoted status opened from its web link (promotions.go) goes straight to the viewer.
        document.body.innerHTML = '';
        const chat = chatWith({ enabled: false });
        chat.statuses = { openViewer: vi.fn() };
        chat.config.openTarget = { type: 'status', user: { id: 1, name: 'Ali' }, status: { id: 3, type: 'text', text: 'Hi' } };
        vi.useFakeTimers();
        new AdsManager(chat);
        vi.runAllTimers();
        vi.useRealTimers();
        expect(chat.statuses.openViewer).toHaveBeenCalledWith([{ user: { id: 1, name: 'Ali' }, statuses: [{ id: 3, type: 'text', text: 'Hi' }] }], 0, 0);
    });

    it('says the promotion has ended instead of following a link back to this same page', async () => {
        // A promoted status whose update is gone: the server answers with nothing to open and no
        // URL, because the card's own link is the page the app would end up on again.
        const promoted = { ...AD, id: 7, promoted: true, internal: true, kind: 'status', click: '/promote/7/go', url: '/promote/7/go' };
        axios.get.mockResolvedValue({ data: { ad: promoted } });
        axios.post.mockImplementation(async (url) => (url === '/ads/7/tap' ? { data: { open: null, url: null } } : { data: {} }));
        const chat = statusChat();
        chat.statuses = { openViewer: vi.fn() };
        new AdsManager(chat);
        await settle();

        const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => {});
        document.querySelector('[data-status-body] .ad-card').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await settle();

        expect(axios.post).toHaveBeenCalledWith('/ads/7/tap', { placement: 'status_list' });
        expect(toast).toHaveBeenCalledWith('This promotion has ended.');
        expect(assign).not.toHaveBeenCalled();
        expect(chat.statuses.openViewer).not.toHaveBeenCalled();
        assign.mockRestore();
    });

    it('never reloads the page a promoted web link already points at', () => {
        const chat = chatWith({ enabled: false });
        chat.config.openTarget = { type: 'url', url: window.location.href };
        const assign = vi.spyOn(window.location, 'assign').mockImplementation(() => {});

        vi.useFakeTimers();
        new AdsManager(chat);
        vi.runAllTimers();
        vi.useRealTimers();

        expect(assign).not.toHaveBeenCalled();
        expect(toast).toHaveBeenCalledWith('This promotion has ended.');
        assign.mockRestore();
    });

    it('renders a banner placement without an image or body', async () => {
        axios.get.mockResolvedValue({ data: { ad: { ...AD, image: null, body: '', format: 'banner' } } });
        document.body.innerHTML = '<div data-message-list></div>';
        new AdsManager({
            config: { ads: { enabled: true, placements: { chat_top: { format: 'banner', container: '[data-message-list]', item: null } } }, routes: { adsNext: '/ads/next' } },
            listMode: 'all',
            el: {},
        });
        await settle();

        const card = document.querySelector('.ad-card');
        expect(card.classList.contains('ad-card-banner')).toBe(true);
        expect(card.querySelector('.ad-card-media')).toBeNull();
        expect(card.querySelector('.ad-card-text')).toBeNull();
        // A banner sits at the top of the screen it belongs to.
        expect(document.querySelector('[data-message-list]').firstElementChild.className).toBe('ad-slot');
    });
});
