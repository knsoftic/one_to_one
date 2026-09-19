// @vitest-environment happy-dom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../../bootstrap', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));
vi.mock('../../lib/toast', () => ({ toast: vi.fn() }));
const buyOnPlay = vi.fn();
vi.mock('../../native/billing', () => ({ buyOnPlay: (...args) => buyOnPlay(...args) }));

import axios from '../../bootstrap';
import { toast } from '../../lib/toast';
import { initWallet } from '../wallet';

const routes = {
    wallet: '/settings/wallet', walletHistory: '/settings/wallet/history', walletWithdraw: '/settings/wallet/withdraw',
    payBegin: '/pay', payShow: '/pay/__ID__', payProof: '/pay/__ID__/proof', payCancel: '/pay/__ID__', payPlayVerify: '/pay/play/verify',
};

const pack = (id, extra = {}) => ({ id, name: `Pack ${id}`, coins: 500 * id, bonus_coins: id === 2 ? 50 : 0, total_coins: 500 * id + (id === 2 ? 50 : 0), price_minor: 49900 * id, currency: 'PKR', price_display: `Rs ${499 * id}`, play_product_id: `coins_${id}`, ...extra });

const payload = (extra = {}) => ({
    summary: { balance: 1250, withdrawable: 200, purchased: 1050, earned_total: 300, purchased_total: 2000, spent_total: 1050, frozen: false, withdraw_enabled: false },
    packs: [pack(1), pack(2)],
    methods: ['manual', 'stripe', 'paypal'],
    pending: [],
    play: { enabled: false, accountHash: 'abc', minAppCode: null },
    history: { data: [{ id: 9, type: 'referral', label: 'Referral reward', amount: 50, withdrawable_delta: 50, note: 'Invited Sara', created_at: '2026-09-10T10:00:00Z' }, { id: 8, type: 'badge', label: 'Verified badge', amount: -500, withdrawable_delta: 0, note: null, created_at: '2026-09-09T10:00:00Z' }], page: 1, has_more: true, next_page: 2 },
    refund_url: '/refunds',
    ...extra,
});

function mount(config = {}, { active = true } = {}) {
    document.body.innerHTML = `
        <span data-wallet-chip>0</span>
        <section data-settings-section="wallet" class="${active ? 'is-active' : ''}">
            <div class="wa-group" data-wallet data-route="/settings/wallet"></div>
        </section>`;
    return initWallet({ routes, paid: { enabled: true, platform: 'web', withdraw: false, play: { enabled: false } }, ...config });
}

const text = (selector) => document.querySelector(selector)?.textContent.replace(/\s+/g, ' ').trim();

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] });
    globalThis.crypto.randomUUID = () => '11111111-1111-4111-8111-111111111111';
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
    vi.clearAllMocks();
});

describe('Wallet', () => {
    it('shows balance, earned and purchased, the packs with bonus, the history and the chip', async () => {
        axios.get.mockResolvedValue({ data: payload() });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-balance]')).not.toBeNull());

        expect(text('[data-wallet-balance]')).toBe('1,250');
        expect(text('[data-wallet-earned] .wallet-tile-value')).toBe('200');
        expect(text('[data-wallet-earned] .wallet-tile-sub')).toBe('300 earned in total');
        expect(text('[data-wallet-purchased] .wallet-tile-value')).toBe('1,050');
        expect(document.querySelectorAll('[data-wallet-pack]')).toHaveLength(2);
        expect(text('[data-wallet-pack="2"] .wallet-pack-bonus')).toBe('+50');
        expect(text('[data-wallet-pack="2"] .wallet-pack-price')).toBe('Rs 998');
        expect(document.querySelectorAll('.wallet-txn')).toHaveLength(2);
        expect(text('.wallet-txn[data-wallet-txn="9"] .wallet-txn-amount')).toBe('+50');
        expect(document.querySelector('.wallet-txn[data-wallet-txn="8"] .wallet-txn-amount').classList.contains('is-debit')).toBe(true);
        expect(text('[data-wallet-chip]')).toBe('1,250');
        expect(document.querySelector('.wallet-links a').getAttribute('href')).toBe('/refunds');
    });

    it('waits until the section is opened before loading', async () => {
        axios.get.mockResolvedValue({ data: payload() });
        mount({}, { active: false });
        expect(axios.get).not.toHaveBeenCalled();
        document.dispatchEvent(new CustomEvent('settings:section', { detail: { name: 'wallet' } }));
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-balance]')).not.toBeNull());
    });

    it('shows Withdraw as "Coming soon" and only toasts when pressed', async () => {
        axios.get.mockResolvedValue({ data: payload() });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-withdraw]')).not.toBeNull());

        const button = document.querySelector('[data-wallet-withdraw]');
        expect(button.textContent).toContain('Coming soon');
        expect(button.getAttribute('aria-disabled')).toBe('true');
        button.click();
        expect(toast).toHaveBeenCalledWith('Withdrawals are coming soon.', expect.anything());
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('posts a withdrawal when enabled and shows Coming soon on a 409', async () => {
        axios.get.mockResolvedValue({ data: payload() });
        axios.post.mockRejectedValue({ response: { status: 409, data: { code: 'coming_soon' } } });
        mount({ paid: { enabled: true, platform: 'web', withdraw: true, play: {} } });
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-withdraw]')).not.toBeNull());

        expect(document.querySelector('[data-wallet-withdraw]').textContent).not.toContain('Coming soon');
        document.querySelector('[data-wallet-withdraw]').click();
        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Withdrawals are coming soon.', expect.anything()));
        expect(axios.post).toHaveBeenCalledWith(routes.walletWithdraw);
    });

    it('builds the method sheet only from the payload and starts a payment with a client token', async () => {
        axios.get.mockResolvedValue({ data: payload({ methods: ['manual', 'stripe'] }) });
        axios.post.mockResolvedValue({ data: { payment: { id: 5, status: 'pending', gateway: 'stripe' }, redirect: 'https://checkout.stripe.test/s' } });
        const assign = vi.fn();
        vi.stubGlobal('location', { ...window.location, assign });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-pack="1"]')).not.toBeNull());

        document.querySelector('[data-wallet-pack="1"]').click();
        const methods = [...document.querySelectorAll('[data-wallet-method]')].map((b) => b.dataset.walletMethod);
        expect(methods).toEqual(['manual', 'stripe']);
        expect(document.querySelector('[data-wallet-method="paypal"]')).toBeNull();
        expect(document.querySelector('[data-wallet-method="play"]')).toBeNull();

        document.querySelector('[data-wallet-method="stripe"]').click();
        await vi.waitFor(() => expect(axios.post).toHaveBeenCalled());
        expect(axios.post).toHaveBeenCalledWith(routes.payBegin, { purpose: 'coins', item_id: 1, gateway: 'stripe', client_token: '11111111-1111-4111-8111-111111111111' });
        await vi.waitFor(() => expect(assign).toHaveBeenCalledWith('https://checkout.stripe.test/s'));
        vi.unstubAllGlobals();
    });

    it('renders the manual instructions and the proof form, and uploads it as multipart', async () => {
        const instructions = { methods: { jazzcash: { label: 'JazzCash', text: '0300 1234567 (Ali)' } }, note: 'Write your username', amount: 49900, currency: 'PKR', amount_display: 'Rs 499' };
        axios.get
            .mockResolvedValueOnce({ data: payload({ methods: ['manual'] }) })
            .mockResolvedValueOnce({ data: payload({ methods: ['manual'], pending: [{ id: 7, status: 'pending', gateway: 'manual', purpose: 'coins', item: '500 coins', amount_display: 'Rs 499', created_at: '2026-09-10T10:00:00Z', manual_method: null, proof_ref: null, review_note: null }] }) })
            .mockResolvedValueOnce({ data: payload({ methods: ['manual'], pending: [{ id: 7, status: 'review', gateway: 'manual', purpose: 'coins', item: '500 coins', amount_display: 'Rs 499', created_at: '2026-09-10T10:00:00Z', manual_method: 'jazzcash', proof_ref: 'TX1', review_note: null }] }) });
        axios.post
            .mockResolvedValueOnce({ data: { payment: { id: 7, status: 'pending', gateway: 'manual' }, instructions } })
            .mockResolvedValueOnce({ data: { payment: { id: 7, status: 'review' } } });
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-pack="1"]')).not.toBeNull());

        // One method only: no sheet, straight to the payment.
        document.querySelector('[data-wallet-pack="1"]').click();
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-pending="7"]')).not.toBeNull());
        expect(axios.post).toHaveBeenCalledWith(routes.payBegin, expect.objectContaining({ gateway: 'manual', item_id: 1 }));
        expect(text('.wallet-instructions')).toContain('0300 1234567 (Ali)');
        expect(text('.wallet-instructions')).toContain('Write your username');
        expect(document.querySelector('[data-wallet-proof="7"] option[value="jazzcash"]')).not.toBeNull();

        const form = document.querySelector('[data-wallet-proof="7"]');
        form.querySelector('[name="ref"]').value = 'TX1';
        form.querySelector('[name="screenshot"]').required = false;
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(axios.post).toHaveBeenCalledTimes(2));
        const [url, body, options] = axios.post.mock.calls[1];
        expect(url).toBe('/pay/7/proof');
        expect(body).toBeInstanceOf(FormData);
        expect(body.get('ref')).toBe('TX1');
        expect(body.get('method')).toBe('jazzcash');
        expect(options.headers['Content-Type']).toBe('multipart/form-data');
        await vi.waitFor(() => expect(text('[data-wallet-pending="7"]')).toContain('Waiting for review'));
    });

    it('polls pay.show every 3 seconds for a Stripe payment until it is fulfilled', async () => {
        const stripePending = { id: 4, status: 'pending', gateway: 'stripe', purpose: 'coins', item: '500 coins', amount_display: 'Rs 499', created_at: '2026-09-10T10:00:00Z' };
        axios.get.mockImplementation(async (url) => {
            if (url === routes.wallet) return { data: payload({ pending: axios.get.mock.calls.filter(([u]) => u === '/pay/4').length >= 2 ? [] : [stripePending] }) };
            const polls = axios.get.mock.calls.filter(([u]) => u === '/pay/4').length;
            return { data: { payment: { ...stripePending, status: polls >= 2 ? 'fulfilled' : 'pending', coins: 500 } } };
        });
        mount();
        await vi.waitFor(() => expect(text('[data-wallet-pending="4"]')).toContain('Confirming'));

        await vi.advanceTimersByTimeAsync(3_000);
        expect(axios.get.mock.calls.filter(([u]) => u === '/pay/4')).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(3_000);
        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('500 coins added to your wallet.', expect.objectContaining({ type: 'success' })));
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-pending="4"]')).toBeNull());
        await vi.advanceTimersByTimeAsync(6_000);
        expect(axios.get.mock.calls.filter(([u]) => u === '/pay/4')).toHaveLength(2);
    });

    it('on Android renders only Google Play and buys through the native bridge', async () => {
        axios.get.mockResolvedValue({ data: payload({ methods: ['play'], play: { enabled: true, accountHash: 'hash1', minAppCode: null } }) });
        buyOnPlay.mockResolvedValue({ status: 'fulfilled', message: 'Coins added.' });
        mount({ paid: { enabled: true, platform: 'android', withdraw: false, play: { enabled: true, accountHash: 'hash1', minAppCode: null } } });
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-pack="2"]')).not.toBeNull());

        expect(document.body.innerHTML).not.toContain('JazzCash');
        expect(document.body.innerHTML).not.toContain('Stripe');
        expect(document.body.innerHTML).not.toContain('PayPal');
        expect(text('[data-wallet-pack="2"] .wallet-pack-price')).toBe('Google Play');

        document.querySelector('[data-wallet-pack="2"]').click();
        await vi.waitFor(() => expect(buyOnPlay).toHaveBeenCalledWith({ productId: 'coins_2', accountHash: 'hash1', verifyUrl: routes.payPlayVerify }));
        expect(document.querySelector('[data-wallet-method]')).toBeNull();
        expect(axios.post).not.toHaveBeenCalled();
        await vi.waitFor(() => expect(toast).toHaveBeenCalledWith('Coins added.', expect.objectContaining({ type: 'success' })));
    });

    it('loads more history', async () => {
        axios.get.mockImplementation(async (url) => (url === routes.wallet
            ? { data: payload() }
            : { data: { data: [{ id: 7, type: 'purchase', label: 'Bought coins', amount: 1000, note: null, created_at: '2026-09-01T10:00:00Z' }], page: 2, has_more: false } }));
        mount();
        await vi.waitFor(() => expect(document.querySelector('[data-wallet-load-more]')).not.toBeNull());

        document.querySelector('[data-wallet-load-more]').click();
        await vi.waitFor(() => expect(document.querySelectorAll('.wallet-txn')).toHaveLength(3));
        expect(axios.get).toHaveBeenLastCalledWith(routes.walletHistory, { params: { page: 2 } });
        expect(document.querySelector('[data-wallet-load-more]')).toBeNull();
    });
});
