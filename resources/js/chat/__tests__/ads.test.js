// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from '../../bootstrap';
import { AdsManager } from '../ads';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: { ad: null } })) } }));
vi.mock('../../lib/native', () => ({ isNativeApp: () => false }));

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
