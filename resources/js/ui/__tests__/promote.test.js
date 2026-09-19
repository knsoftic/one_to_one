// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('../../lib/modal', () => ({ confirmDialog: vi.fn(async () => 'stop') }));
vi.mock('../../lib/toast', () => ({ toast: vi.fn() }));

import axios from '../../bootstrap';
import { confirmDialog } from '../../lib/modal';
import { toast } from '../../lib/toast';
import { PRESETS, STEPS, initPromote, quoteViews } from '../promote';

const routes = {
    settings: '/settings',
    promotions: '/settings/promote',
    promotionsQuote: '/settings/promote/quote',
    promotionsStore: '/settings/promote',
    promotionShow: '/settings/promote/__ID__',
    promotionStop: '/settings/promote/__ID__/stop',
};

const row = (extra = {}) => ({
    id: 7, kind: 'status', kind_label: 'Status', status: 'active', status_label: 'Running', review_note: null,
    card: { id: 7, title: 'Status by Ali', body: 'Mangoes', image: null, cta: 'View status', sponsor: 'Ali', kind: 'status', promoted: true, internal: true, url: '/promote/7/go' },
    impressions: 250, clicks: 10, ctr: 4, view_budget: 1000, remaining: 750, coins_spent: 100, coins_refunded: 0, rate_per_1000: 100, can_stop: true,
    ...extra,
});

const index = (extra = {}) => ({
    targets: [
        { kind: 'status', id: 3, title: 'Status by Ali', subtitle: 'Mangoes', image: null, background: 'teal', already_promoted: false, expires_at: null },
        { kind: 'channel', id: 9, title: 'Cricket', subtitle: 'Daily', image: '/c.jpg', background: null, already_promoted: false, expires_at: null },
        { kind: 'channel', id: 10, title: 'Taken', subtitle: null, image: null, background: null, already_promoted: true, expires_at: null },
    ],
    rates: { status: 100, channel: 100, community: 100, business: 120, card: 150, link: 200 },
    min: 50, max: 50000, max_active: 5,
    placements: { status: ['status_list', 'chat_list'], channel: ['channels', 'chat_list'], community: ['channels', 'chat_list'], business: ['chat_list'], card: ['chat_list'], link: ['chat_list'] },
    auto_approve: false, balance: 300, frozen: false,
    promotions: [row()],
    ...extra,
});

function mount({ kind = '', id = '' } = {}) {
    document.body.innerHTML = `
        <section data-settings-section="promote" class="is-active">
            <div data-promote data-route="/settings/promote" data-kind="${kind}" data-target="${id}"></div>
        </section>`;
    return initPromote({ routes, user: { name: 'Ali' }, paid: { enabled: true, promote: true } });
}

const flush = async () => {
    for (let i = 0; i < 6; i++) await Promise.resolve();
};

const $ = (s) => document.querySelector(s);
const type = (selector, value) => {
    const el = $(selector);
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
};

beforeEach(() => {
    sessionStorage.clear();
    axios.get.mockImplementation(async (url) => (url.includes('__') || /\/\d+$/.test(url) ? { data: { ...row(), days: [], placements: [] } } : { data: index() }));
    axios.post.mockResolvedValue({ data: {} });
});

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('Settings › Promote', () => {
    it('mirrors the server quote maths (integer division)', () => {
        expect(quoteViews(100, 100)).toBe(1000);
        expect(quoteViews(250, 150)).toBe(1666);
        expect(quoteViews(0, 100)).toBe(0);
        expect(quoteViews(99, 0)).toBe(99000);
        expect(STEPS).toEqual(['target', 'card', 'budget', 'review']);
        expect(PRESETS[0]).toBe(100);
    });

    it('lists my promotions with a status chip, budget bar and numbers', async () => {
        mount();
        await flush();

        expect(axios.get).toHaveBeenCalledWith('/settings/promote');
        const item = $('[data-promote-open="7"]');
        expect(item).not.toBeNull();
        expect(item.querySelector('[data-promote-chip]').textContent).toBe('Running');
        expect(item.querySelector('[data-promote-chip]').classList.contains('is-active')).toBe(true);
        expect(item.querySelector('.promote-budget-bar > span').style.width).toBe('25%');
        expect(item.querySelector('[data-promote-stats]').textContent).toContain('250 views · 10 taps · 4% CTR');
        expect($('[data-promote-balance]').textContent).toBe('300');
        expect($('[data-promote-new]')).not.toBeNull();
    });

    it('opens the detail and stops after confirming', async () => {
        const promote = mount();
        await flush();

        $('[data-promote-open="7"]').click();
        await flush();
        expect(axios.get).toHaveBeenCalledWith('/settings/promote/7');
        expect($('[data-promote-detail]')).not.toBeNull();
        expect($('.ad-card-tag').textContent).toBe('Promoted · Ali');
        expect($('[data-promote-stop="7"]')).not.toBeNull();

        axios.post.mockResolvedValue({ data: { promotion: row({ status: 'stopped', status_label: 'Stopped', can_stop: false, coins_refunded: 75 }), balance: 375 } });
        $('[data-promote-stop="7"]').click();
        await flush();

        expect(confirmDialog).toHaveBeenCalledWith(expect.objectContaining({ title: 'Stop this promotion?' }));
        expect(axios.post).toHaveBeenCalledWith('/settings/promote/7/stop');
        expect(toast).toHaveBeenCalledWith(expect.stringContaining('75 coins'), expect.objectContaining({ type: 'success' }));
        expect(promote.data.balance).toBe(375);
    });

    it('walks the four steps, prefills the card and quotes live', async () => {
        const promote = mount();
        await flush();

        $('[data-promote-new]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('target');
        // Only kinds I have something for, plus card and link.
        expect([...document.querySelectorAll('[data-wizard-kind]')].map((b) => b.dataset.wizardKind)).toEqual(['status', 'channel', 'card', 'link']);

        $('[data-wizard-kind="channel"]').click();
        expect(document.querySelectorAll('[data-wizard-target]')).toHaveLength(2);
        $('[data-wizard-target="10"]').click(); // already promoted
        expect(toast).toHaveBeenCalledWith('This is already being promoted.', expect.anything());
        expect($('[data-promote-wizard]').dataset.step).toBe('target');

        $('[data-wizard-target="9"]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('card');
        expect($('[data-wizard-field="title"]').value).toBe('Cricket');
        expect($('[data-wizard-field="body"]').value).toBe('Daily');
        expect($('[data-wizard-field="cta"]').value).toBe('Follow channel');
        expect($('[data-wizard-preview] .ad-card-title').textContent).toBe('Cricket');
        expect($('[data-wizard-preview] .ad-card-media').style.backgroundImage).toContain('/c.jpg');
        expect($('[data-wizard-field="url"]')).toBeNull(); // internal kinds have no link field

        type('[data-wizard-field="title"]', 'Cricket news');
        expect($('[data-wizard-preview] .ad-card-title').textContent).toBe('Cricket news');

        $('[data-wizard-next]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('budget');
        expect($('[data-wizard-views]').textContent).toBe('1,000'); // 100 coins at 100 per 1,000
        $('[data-wizard-preset="250"]').click();
        expect($('[data-wizard-views]').textContent).toBe('2,500');
        type('[data-wizard-field="coins"]', '175');
        expect($('[data-wizard-views]').textContent).toBe('1,750');

        $('[data-wizard-next]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('review');
        expect($('[data-wizard-submit]').textContent).toContain('175 coins');
        expect($('[data-wizard-submit]').disabled).toBe(false);
        expect(promote.wizard.token).toMatch(/^[0-9a-f-]{36}$/);
    });

    it('swaps the primary button for Get coins and keeps the draft when the balance is short', async () => {
        mount();
        await flush();
        $('[data-promote-new]').click();
        $('[data-wizard-kind="status"]').click(); // one target ⇒ chosen automatically
        expect($('[data-promote-wizard]').dataset.step).toBe('card');
        $('[data-wizard-next]').click();
        type('[data-wizard-field="coins"]', '500'); // balance is 300

        expect($('[data-wizard-next]')).toBeNull();
        const getCoins = $('[data-wizard-get-coins]');
        expect(getCoins.getAttribute('href')).toBe('/settings?tab=wallet');
        expect($('.promote-quote-short').textContent).toContain('200 more coins');

        getCoins.click();
        const draft = JSON.parse(sessionStorage.getItem('promote:draft'));
        expect(draft).toMatchObject({ kind: 'status', targetId: 3, coins: 500, step: 2 });

        // Coming back from the wallet restores the wizard at the budget step.
        document.body.innerHTML = '';
        mount();
        await flush();
        expect($('[data-promote-wizard]').dataset.step).toBe('budget');
        expect($('[data-wizard-field="coins"]').value).toBe('500');
    });

    it('submits multipart with the client token once and disables the button', async () => {
        const promote = mount();
        await flush();
        $('[data-promote-new]').click();
        $('[data-wizard-kind="card"]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('card');

        $('[data-wizard-next]').click();
        expect(toast).toHaveBeenLastCalledWith(expect.stringContaining('http://'), expect.anything());
        type('[data-wizard-field="url"]', 'https://shop.example/eid');
        type('[data-wizard-field="title"]', 'Eid offer');
        $('[data-wizard-next]').click();
        $('[data-wizard-next]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('review');

        let resolve;
        axios.post.mockReturnValue(new Promise((r) => (resolve = r)));
        $('[data-promote-wizard]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect($('[data-wizard-submit]').disabled).toBe(true);
        $('[data-promote-wizard]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        expect(axios.post).toHaveBeenCalledTimes(1);

        const [url, form, options] = axios.post.mock.calls[0];
        expect(url).toBe('/settings/promote');
        expect(form).toBeInstanceOf(FormData);
        expect(form.get('kind')).toBe('card');
        expect(form.get('url')).toBe('https://shop.example/eid');
        expect(form.get('title')).toBe('Eid offer');
        expect(form.get('coins')).toBe('100');
        expect(form.get('client_token')).toBe(promote.wizard.token);
        expect(options.headers['Content-Type']).toBe('multipart/form-data');

        resolve({ data: { promotion: row({ id: 8, kind: 'card', status: 'pending', status_label: 'Under review' }), balance: 200 } });
        await flush();
        expect(sessionStorage.getItem('promote:draft')).toBeNull();
        expect($('[data-promote-open="8"]')).not.toBeNull();
        expect($('[data-promote-balance]').textContent).toBe('200');
    });

    it('requires the visibility tick for a status and preselects from the query', async () => {
        mount({ kind: 'status', id: '3' });
        await flush();

        expect($('[data-promote-wizard]').dataset.step).toBe('card');
        $('[data-wizard-next]').click();
        $('[data-wizard-next]').click();
        expect($('[data-promote-wizard]').dataset.step).toBe('review');
        expect($('[data-wizard-submit]').disabled).toBe(true);

        const agree = $('[data-wizard-agree]');
        agree.checked = true;
        agree.dispatchEvent(new Event('change', { bubbles: true }));
        expect($('[data-wizard-submit]').disabled).toBe(false);
    });

    it('shows Get coins again when the server says the balance is short', async () => {
        mount({ kind: 'channel', id: '9' });
        await flush();
        $('[data-wizard-next]').click();
        $('[data-wizard-next]').click();
        axios.post.mockRejectedValue({ response: { status: 422, data: { code: 'insufficient_coins', needed: 100, balance: 20 } } });

        $('[data-promote-wizard]').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await flush();

        expect($('[data-promote-wizard]').dataset.step).toBe('budget');
        expect($('[data-wizard-get-coins]')).not.toBeNull();
        expect(JSON.parse(sessionStorage.getItem('promote:draft')).targetId).toBe(9);
    });
});
