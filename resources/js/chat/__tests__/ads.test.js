// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import axios from '../../bootstrap';
import { confirmDialog } from '../../lib/modal';
import { AdsManager } from '../ads';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(async () => ({ data: {} })), get: vi.fn(async () => ({ data: { ad: null } })) } }));
vi.mock('../../lib/modal', () => ({ confirmDialog: vi.fn() }));
vi.mock('../../lib/native', () => ({ isNativeApp: () => false }));

const AD = { title: 'Buy now', body: 'Cheap', cta: 'Shop', sponsor: 'ACME', image: 'https://x/y.jpg', click: '/ads/1/go', url: 'https://acme.test' };

function chatWith(ads, chats = 8) {
    const list = document.createElement('div');
    list.innerHTML = Array.from({ length: chats }, (_, i) => `<button class="conversation-item">chat ${i}</button>`).join('');
    document.body.append(list);
    return {
        config: { ads, routes: { adsNext: '/ads/next', adsConsent: '/ads/consent' } },
        listMode: 'all',
        el: { conversationList: list },
    };
}

describe('Ads in the chat list', () => {
    beforeEach(() => {
        axios.get.mockResolvedValue({ data: { ad: null } });
        axios.post.mockResolvedValue({ data: { personalised: true } });
    });
    afterEach(() => {
        document.body.innerHTML = '';
        vi.clearAllMocks();
    });

    it('does nothing when ads are off', () => {
        new AdsManager(chatWith({ enabled: false }));
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('places one sponsored card a few rows down, and moves it (no duplicate) on re-render', async () => {
        axios.get.mockResolvedValue({ data: { ad: AD } });
        const chat = chatWith({ enabled: true, decided: true, everyChats: 6 });
        new AdsManager(chat);
        await Promise.resolve();
        await Promise.resolve();

        const cards = chat.el.conversationList.querySelectorAll('.ad-slot');
        expect(cards).toHaveLength(1);
        expect(chat.el.conversationList.querySelector('.ad-card-title').textContent).toBe('Buy now');
        expect(chat.el.conversationList.querySelector('.ad-card').getAttribute('href')).toBe('/ads/1/go');
        expect(chat.el.conversationList.querySelector('.ad-card').getAttribute('target')).toBe('_blank');
        // After the 6th chat (index 5).
        const nodes = [...chat.el.conversationList.children];
        expect(nodes.indexOf(chat.el.conversationList.querySelector('.ad-slot'))).toBe(6);

        document.dispatchEvent(new CustomEvent('chat:conversations-rendered'));
        expect(chat.el.conversationList.querySelectorAll('.ad-slot')).toHaveLength(1);
    });

    it('renders without an image and hides the card in archived or locked folders', async () => {
        axios.get.mockResolvedValue({ data: { ad: { ...AD, image: null, body: '' } } });
        const chat = chatWith({ enabled: true, decided: true, everyChats: 4 });
        const ads = new AdsManager(chat);
        await Promise.resolve();
        await Promise.resolve();
        expect(chat.el.conversationList.querySelector('.ad-card-media')).toBeNull();
        expect(chat.el.conversationList.querySelector('.ad-card-text')).toBeNull();

        chat.listMode = 'archived';
        ads.place();
        expect(chat.el.conversationList.querySelector('.ad-slot')).toBeNull();
    });

    it('asks for consent once when undecided and records the choice', async () => {
        vi.useFakeTimers();
        confirmDialog.mockResolvedValue('on');
        const chat = chatWith({ enabled: true, decided: false });
        new AdsManager(chat);
        await vi.advanceTimersByTimeAsync(1600);

        expect(confirmDialog).toHaveBeenCalledOnce();
        expect(axios.post).toHaveBeenCalledWith('/ads/consent', expect.objectContaining({ personalised: true, platform: 'web' }));
        expect(chat.config.ads.decided).toBe(true);
        vi.useRealTimers();
    });

    it('records a declined choice too', async () => {
        vi.useFakeTimers();
        confirmDialog.mockResolvedValue(null);
        const chat = chatWith({ enabled: true, decided: false });
        new AdsManager(chat);
        await vi.advanceTimersByTimeAsync(1600);
        expect(axios.post).toHaveBeenCalledWith('/ads/consent', expect.objectContaining({ personalised: false }));
        vi.useRealTimers();
    });
});
