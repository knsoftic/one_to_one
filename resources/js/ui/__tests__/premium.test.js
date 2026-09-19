// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { post: vi.fn(), get: vi.fn() } }));
vi.mock('../../lib/modal', () => ({ confirmDialog: vi.fn(async () => 'buy') }));
vi.mock('../../lib/toast', () => {
    const toast = vi.fn();
    toast.success = vi.fn();
    toast.error = vi.fn();
    return { toast };
});
vi.mock('../../native/billing', () => ({
    playProducts: vi.fn(async () => [{ productId: 'plan_pro_month', price: 'Rs 1,500.00' }]),
    buyOnPlay: vi.fn(async () => ({ status: 'fulfilled', message: 'Plan active.' })),
}));

import axios from '../../bootstrap';
import { confirmDialog } from '../../lib/modal';
import { toast } from '../../lib/toast';
import { buyOnPlay, playProducts } from '../../native/billing';
import { benefitLines, initPremium } from '../premium';

const routes = {
    premium: '/settings/premium', payBegin: '/pay', payProof: '/pay/__ID__/proof', payPlayVerify: '/pay/play/verify', badgeBuy: '/settings/badge', settings: '/settings',
};

const plan = (overrides = {}) => ({
    id: 1, name: 'Pro', slug: 'pro', description: 'No ads and more', period: 'month', price_minor: 49900, price_display: 'Rs 499', currency: 'PKR', price_usd_minor: 499,
    benefits: { ads_off: true, verified_badge: true, monthly_coins: 100, limits: { upload_mb: 64 } }, play_product_id: 'plan_pro_month', ...overrides,
});
const badge = (overrides = {}) => ({ verified: false, source: null, until: null, lifetime: false, purchasable: true, price: 500, days: 365, ...overrides });
const iso = (days) => new Date(Date.now() + days * 86_400_000).toISOString();

const payload = (overrides = {}) => ({
    plans: [plan(), plan({ id: 2, name: 'Plus', slug: 'plus', price_display: 'Rs 999', benefits: { ads_off: true, verified_badge: false, monthly_coins: 0, limits: {} }, play_product_id: null })],
    active: null, queued: null, badge: badge(), methods: ['manual', 'stripe', 'paypal'],
    play: { enabled: false, accountHash: null, minAppCode: null }, refund_url: '/refunds', ...overrides,
});

async function mount(data, config = {}) {
    axios.get.mockResolvedValue({ data });
    document.body.innerHTML = `
        <section data-settings-section="premium" class="is-active">
            <div data-premium><div class="premium-loading"></div></div>
        </section>`;
    const premium = initPremium({ routes, paid: { enabled: true, platform: 'web' }, ...config });
    await vi.waitFor(() => expect(document.querySelector('[data-premium-plans], [data-premium-current], .premium-empty')).not.toBeNull());
    return premium;
}

const text = (selector) => document.querySelector(selector)?.textContent ?? '';

afterEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('Premium — plans', () => {
    it('renders plan cards with prices and benefit ticks', async () => {
        await mount(payload());

        const cards = document.querySelectorAll('[data-premium-plan]');
        expect(cards).toHaveLength(2);
        expect(text('[data-premium-plan="1"] .premium-plan-price')).toContain('Rs 499');
        expect(text('[data-premium-plan="1"] .premium-plan-price')).toContain('/ month');
        expect(document.querySelectorAll('[data-premium-plan="1"] .premium-benefit.is-on')).toHaveLength(4);
        expect(text('[data-premium-plan="1"]')).toContain('Send files up to 64 MB');
        // What Plus leaves out is listed, crossed out.
        expect(document.querySelectorAll('[data-premium-plan="2"] .premium-benefit.is-off')).toHaveLength(2);
        expect(document.querySelectorAll('[data-premium-choose]')).toHaveLength(2);
        expect(document.querySelector('[data-premium-current]')).toBeNull();
        expect(document.querySelector('.premium-refunds a').getAttribute('href')).toBe('/refunds');
    });

    it('benefitLines turns a snapshot into readable lines', () => {
        const lines = benefitLines({ ads_off: false, verified_badge: true, monthly_coins: 0, limits: { group_members: 1024, storage_mb: 2048 } });
        expect(lines.map((l) => `${l.on ? '+' : '-'}${l.text}`)).toEqual([
            '-No ads', '+Verified badge', '-0 free coins every month', '+Groups of up to 1,024 people', '+2 GB of storage',
        ]);
    });

    it('shows the current plan, the queued one, and no Renew while the end is far away', async () => {
        await mount(payload({
            active: { id: 5, plan_id: 1, plan: 'Pro', status: 'active', starts_at: iso(-10), ends_at: iso(20), benefits: plan().benefits, renew_available: false },
            queued: { id: 6, plan_id: 2, plan: 'Plus', status: 'queued', starts_at: iso(20), ends_at: iso(50), benefits: {} },
        }));

        expect(text('[data-premium-current] .premium-current-title')).toBe('Pro plan');
        expect(text('[data-premium-current] .premium-current-text')).toContain('Until');
        expect(document.querySelectorAll('[data-premium-current] .premium-benefit.is-on')).toHaveLength(4);
        expect(document.querySelectorAll('[data-premium-current] .premium-benefit.is-off')).toHaveLength(0);
        expect(text('[data-premium-queued]')).toContain('Plus');
        expect(text('[data-premium-queued]')).toContain('starts on');
        expect(document.querySelector('[data-premium-renew]')).toBeNull();
        expect(text('[data-premium-plan="1"] .premium-plan-state')).toContain('Current plan');
        expect(text('[data-premium-plan="2"] .premium-plan-state')).toContain('Starts on');
        expect(document.querySelectorAll('[data-premium-choose]')).toHaveLength(0);
    });

    it('offers Renew within 7 days of the end', async () => {
        await mount(payload({
            active: { id: 5, plan_id: 1, plan: 'Pro', status: 'active', starts_at: iso(-25), ends_at: iso(5), benefits: plan().benefits, renew_available: true },
        }));

        expect(document.querySelector('[data-premium-current] [data-premium-renew]')).not.toBeNull();
        expect(text('[data-premium-plan="1"] [data-premium-renew]')).toBe('Renew');
        expect(text('[data-premium-plan="2"] [data-premium-choose]')).toBe('Choose');
    });
});

describe('Premium — verified badge', () => {
    it('sells the badge when not verified', async () => {
        await mount(payload());
        expect(text('[data-premium-badge] .premium-badge-title')).toBe('Get verified');
        expect(text('[data-premium-badge-buy]')).toContain('Get verified');
        expect(text('[data-premium-badge-buy]')).toContain('500 coins');
        expect(text('[data-premium-badge]')).toContain('1 year');
    });

    it('offers Extend for a coin badge and says Included for a plan badge', async () => {
        await mount(payload({ badge: badge({ verified: true, source: 'coins', until: iso(100) }) }));
        expect(text('[data-premium-badge] .premium-badge-text')).toContain('Verified until');
        expect(text('[data-premium-badge-buy]')).toContain('Extend');

        await mount(payload({ badge: badge({ verified: true, source: 'plan' }) }));
        expect(text('[data-premium-badge] .premium-badge-text')).toContain('Included in your plan');
        expect(text('[data-premium-badge-buy]')).toContain('Extend');
        expect(document.querySelector('[data-premium-badge]').dataset.badgeSource).toBe('plan');

        await mount(payload({ badge: badge({ verified: true, lifetime: true, purchasable: false, source: 'admin' }) }));
        expect(text('[data-premium-badge]')).toContain('for good');
        expect(document.querySelector('[data-premium-badge-buy]')).toBeNull();

        await mount(payload({ badge: badge({ purchasable: false, price: 0 }) }));
        expect(document.querySelector('[data-premium-badge-buy]')).toBeNull();
    });

    it('buys with a client token and points to the wallet when coins run out', async () => {
        axios.post.mockResolvedValueOnce({ data: { ok: true } });
        await mount(payload());

        document.querySelector('[data-premium-badge-buy]').click();
        await vi.waitFor(() => expect(axios.post).toHaveBeenCalled());
        expect(confirmDialog).toHaveBeenCalled();
        const [url, body] = axios.post.mock.calls[0];
        expect(url).toBe(routes.badgeBuy);
        expect(body.client_token).toMatch(/[0-9a-f-]{8,}/);
        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('You are verified.', expect.objectContaining({ type: 'success' })));

        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { code: 'insufficient_coins', needed: 120, balance: 380, message: 'Not enough coins.' } } });
        document.querySelector('[data-premium-badge-buy]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-premium-badge-error]').hidden).toBe(false));
        expect(text('[data-premium-badge-error]')).toContain('120 more coins');
        expect(document.querySelector('[data-premium-wallet]')).not.toBeNull();
    });
});

describe('Premium — paying', () => {
    it('builds the method sheet only from the payload and renders manual instructions after begin', async () => {
        axios.post.mockResolvedValueOnce({ data: {
            payment: { id: 7, status: 'pending' },
            instructions: { methods: { jazzcash: { label: 'JazzCash', text: '0300 1234567 (Ali)' }, bank: { label: 'Bank transfer', text: 'HBL 1234' } }, note: 'Write your number in the reference.', amount: 49900, currency: 'PKR', amount_display: 'Rs 499' },
        } });
        await mount(payload({ methods: ['manual', 'stripe'] }));

        document.querySelector('[data-premium-choose="1"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-premium-methods]')).not.toBeNull());
        const methods = [...document.querySelectorAll('[data-premium-method]')].map((b) => b.dataset.premiumMethod);
        expect(methods).toEqual(['manual', 'stripe']);
        expect(document.querySelector('[data-premium-method="play"]')).toBeNull();
        expect(document.querySelector('[data-premium-method="paypal"]')).toBeNull();

        document.querySelector('[data-premium-method="manual"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-premium-manual]')).not.toBeNull());
        expect(document.querySelector('[data-premium-methods]')).toBeNull();
        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, body] = axios.post.mock.calls[0];
        expect(url).toBe(routes.payBegin);
        expect(body).toMatchObject({ purpose: 'plan', item_id: 1, gateway: 'manual' });
        expect(body.client_token).toMatch(/[0-9a-f-]{8,}/);

        expect(text('[data-premium-manual]')).toContain('Rs 499');
        expect(text('[data-manual-method="jazzcash"]')).toContain('0300 1234567 (Ali)');
        expect(text('[data-premium-manual]')).toContain('Write your number in the reference.');
        const form = document.querySelector('[data-premium-proof]');
        expect(form.getAttribute('action')).toBe('/pay/7/proof');
        expect([...form.querySelectorAll('select[name="method"] option')].map((o) => o.value)).toEqual(['jazzcash', 'bank']);
        expect(form.querySelector('input[name="ref"]')).not.toBeNull();
        expect(form.querySelector('input[name="screenshot"]').type).toBe('file');
        expect(document.querySelector('[data-premium-plans]')).toBeNull();

        // Sending the proof posts multipart to the payment's proof route and shows the waiting note.
        axios.post.mockResolvedValueOnce({ data: { payment: { id: 7, status: 'review' } } });
        form.querySelector('input[name="ref"]').value = 'TX123';
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(document.querySelector('.premium-manual-sent')).not.toBeNull());
        expect(axios.post.mock.calls[1][0]).toBe('/pay/7/proof');
        expect(axios.post.mock.calls[1][1]).toBeInstanceOf(FormData);
        expect(document.querySelector('[data-premium-proof]')).toBeNull();
    });

    it('follows a redirect from a hosted gateway', async () => {
        axios.post.mockResolvedValueOnce({ data: { payment: { id: 8 }, redirect: 'https://checkout.stripe.com/x' } });
        const assign = vi.fn();
        vi.spyOn(window, 'location', 'get').mockReturnValue({ assign });
        await mount(payload({ methods: ['stripe'] }));

        document.querySelector('[data-premium-choose="1"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-premium-method="stripe"]')).not.toBeNull());
        document.querySelector('[data-premium-method="stripe"]').click();
        await vi.waitFor(() => expect(assign).toHaveBeenCalledWith('https://checkout.stripe.com/x'));
        expect(axios.post.mock.calls[0][1].gateway).toBe('stripe');
    });

    it('in the Android app lists only Play plans with Play prices and buys through Google Play', async () => {
        await mount(payload({ methods: ['play'], play: { enabled: true, accountHash: 'abc123', minAppCode: null } }), { paid: { enabled: true, platform: 'android' } });

        expect(playProducts).toHaveBeenCalledWith(['plan_pro_month']);
        expect(document.querySelectorAll('[data-premium-plan]')).toHaveLength(1); // Plus has no Play product
        expect(text('[data-premium-plan="1"] .premium-plan-price')).toContain('Rs 1,500.00');
        expect(text('[data-premium-plan="1"] .premium-plan-price')).not.toContain('Rs 499');
        expect(document.body.textContent).not.toContain('JazzCash');
        expect(document.body.textContent).not.toContain('PayPal');

        document.querySelector('[data-premium-choose="1"]').click();
        await vi.waitFor(() => expect(buyOnPlay).toHaveBeenCalled());
        expect(buyOnPlay).toHaveBeenCalledWith({ productId: 'plan_pro_month', accountHash: 'abc123', verifyUrl: routes.payPlayVerify });
        expect(axios.post).not.toHaveBeenCalled();                 // never a web gateway from the app
        expect(document.querySelector('[data-premium-methods]')).toBeNull();
        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Plan active.', expect.objectContaining({ type: 'success' })));
    });

    it('asks for an app update when the build is below the minimum', async () => {
        await mount(payload({ methods: ['play'], play: { enabled: true, accountHash: 'abc123', minAppCode: 20 } }), { paid: { enabled: true, platform: 'android' }, native: { build: 10 } });

        document.querySelector('[data-premium-choose="1"]').click();
        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Update the app to buy a plan.', expect.objectContaining({ type: 'info' })));
        expect(buyOnPlay).not.toHaveBeenCalled();
    });

    it('waits until the section is opened, then loads', async () => {
        axios.get.mockResolvedValue({ data: payload() });
        document.body.innerHTML = '<section data-settings-section="premium"><div data-premium></div></section>';
        initPremium({ routes, paid: { enabled: true, platform: 'web' } });
        expect(axios.get).not.toHaveBeenCalled();

        document.dispatchEvent(new CustomEvent('settings:section', { detail: { name: 'premium' } }));
        await vi.waitFor(() => expect(document.querySelector('[data-premium-plans]')).not.toBeNull());
        expect(axios.get).toHaveBeenCalledWith(routes.premium);
    });
});
