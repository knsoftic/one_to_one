import { beforeEach, describe, expect, it, vi } from 'vitest';

const native = { isNative: true };
const calls = [];

vi.mock('../../lib/native', () => ({ isNativeApp: () => native.isNative }));
vi.mock('../../bootstrap', () => ({
    default: {
        post: vi.fn(async (url, body) => {
            calls.push(['verify', body.purchase_token]);
            return { status: 200, data: { status: 'fulfilled', wallet: { balance: 600 } } };
        }),
    },
}));
vi.mock('../plugins', () => {
    const listeners = [];
    return {
        NativeBilling: {
            isAvailable: vi.fn(async () => ({ available: true })),
            getProducts: vi.fn(async ({ ids }) => ({
                products: ids.map((productId) => ({ productId, title: productId, price: 'Rs 500.00', priceMicros: 500000000, currency: 'PKR' })),
            })),
            purchase: vi.fn(async ({ productId }) => {
                calls.push(['purchase', productId]);
                return { purchaseToken: 'tok-1', orderId: 'GPA.1', productId, state: 'purchased' };
            }),
            getPendingPurchases: vi.fn(async () => ({ purchases: [] })),
            consume: vi.fn(async ({ purchaseToken }) => {
                calls.push(['consume', purchaseToken]);
                return { ok: true };
            }),
            addListener: vi.fn((name, fn) => {
                listeners.push(fn);
                return Promise.resolve({ remove: vi.fn() });
            }),
            __listeners: listeners,
        },
    };
});

import axios from '../../bootstrap';
import { BillingError, buyOnPlay, onPurchaseUpdated, playAvailable, playProducts, recoverPurchases } from '../billing';
import { NativeBilling } from '../plugins';

const VERIFY = '/pay/play/verify';
const args = { productId: 'coins_500', accountHash: 'abc', verifyUrl: VERIFY };

const httpError = (status, data = {}) => Object.assign(new Error(`HTTP ${status}`), { response: { status, data } });

beforeEach(() => {
    native.isNative = true;
    calls.length = 0;
    vi.clearAllMocks();
    NativeBilling.__listeners.length = 0;
});

describe('buyOnPlay', () => {
    it('purchases, verifies with the server, then consumes — in that order', async () => {
        const result = await buyOnPlay(args);

        expect(calls).toEqual([
            ['purchase', 'coins_500'],
            ['verify', 'tok-1'],
            ['consume', 'tok-1'],
        ]);
        expect(NativeBilling.purchase).toHaveBeenCalledWith({ productId: 'coins_500', accountHash: 'abc' });
        expect(axios.post).toHaveBeenCalledWith(VERIFY, { product_id: 'coins_500', purchase_token: 'tok-1', order_id: 'GPA.1' });
        expect(result.status).toBe('fulfilled');
        expect(result.wallet).toEqual({ balance: 600 });
        expect(result.purchaseToken).toBe('tok-1');
    });

    it('does not consume when the server answers 503 (Google unreachable) and asks to try again', async () => {
        axios.post.mockRejectedValueOnce(httpError(503, { error: 'google_unavailable' }));

        const error = await buyOnPlay(args).catch((e) => e);

        expect(error).toBeInstanceOf(BillingError);
        expect(error.code).toBe('google_unavailable');
        expect(error.retry).toBe(true);
        expect(error.message).toMatch(/try again/i);
        expect(NativeBilling.consume).not.toHaveBeenCalled();
    });

    it('does not consume on a network failure', async () => {
        axios.post.mockRejectedValueOnce(new Error('Network Error'));

        const error = await buyOnPlay(args).catch((e) => e);

        expect(error.code).toBe('network');
        expect(error.retry).toBe(true);
        expect(NativeBilling.consume).not.toHaveBeenCalled();
    });

    it('treats 202 as pending: a message, no consume', async () => {
        NativeBilling.purchase.mockResolvedValueOnce({ purchaseToken: 'tok-p', orderId: null, productId: 'coins_500', state: 'pending' });
        axios.post.mockResolvedValueOnce({ status: 202, data: { status: 'pending' } });

        const result = await buyOnPlay(args);

        expect(result.status).toBe('pending');
        expect(result.message).toMatch(/waiting for google play/i);
        expect(axios.post).toHaveBeenCalledWith(VERIFY, { product_id: 'coins_500', purchase_token: 'tok-p', order_id: null });
        expect(NativeBilling.consume).not.toHaveBeenCalled();
    });

    it('maps 422 codes to messages and never consumes', async () => {
        axios.post.mockRejectedValueOnce(httpError(422, { error: 'account_mismatch' }));
        const mismatch = await buyOnPlay(args).catch((e) => e);
        expect(mismatch.code).toBe('account_mismatch');
        expect(mismatch.message).toMatch(/different account/i);

        axios.post.mockRejectedValueOnce(httpError(409, { error: 'token_other_account' }));
        const other = await buyOnPlay(args).catch((e) => e);
        expect(other.code).toBe('token_other_account');

        expect(NativeBilling.consume).not.toHaveBeenCalled();
    });

    it('consumes the token on the 422 codes the server will never deliver, so the item can be bought again', async () => {
        for (const code of ['voided', 'consumed', 'cancelled']) {
            vi.clearAllMocks();
            axios.post.mockRejectedValueOnce(httpError(422, { code, message: 'Google refunded this purchase.' }));

            const error = await buyOnPlay(args).catch((e) => e);

            expect(error.code).toBe(code);
            expect(error.terminal).toBe(true);
            expect(NativeBilling.consume).toHaveBeenCalledWith({ purchaseToken: 'tok-1' });
        }
    });

    it('keeps the token on a 422 that may still be delivered elsewhere', async () => {
        for (const code of ['account_mismatch', 'unknown_product', 'order_reused']) {
            vi.clearAllMocks();
            axios.post.mockRejectedValueOnce(httpError(422, { code }));

            const error = await buyOnPlay(args).catch((e) => e);

            expect(error.terminal).toBe(false);
            expect(NativeBilling.consume).not.toHaveBeenCalled();
        }
    });

    it('makes no request when the user cancels the Play sheet', async () => {
        NativeBilling.purchase.mockRejectedValueOnce(Object.assign(new Error('User canceled'), { code: 'cancelled' }));

        const error = await buyOnPlay(args).catch((e) => e);

        expect(error.code).toBe('cancelled');
        expect(error.message).toBe('Purchase cancelled.');
        expect(axios.post).not.toHaveBeenCalled();
        expect(NativeBilling.consume).not.toHaveBeenCalled();
    });

    it('recovers an undelivered purchase when Play says the item is already owned', async () => {
        NativeBilling.purchase.mockRejectedValueOnce(Object.assign(new Error('Already owned'), { code: 'item_already_owned' }));
        NativeBilling.getPendingPurchases.mockResolvedValueOnce({
            purchases: [{ purchaseToken: 'tok-old', orderId: 'GPA.old', productId: 'coins_500', state: 'purchased' }],
        });

        const result = await buyOnPlay(args);

        expect(result.status).toBe('fulfilled');
        expect(NativeBilling.purchase).toHaveBeenCalledTimes(1);
        expect(calls).toEqual([
            ['verify', 'tok-old'],
            ['consume', 'tok-old'],
        ]);
    });

    it('rejects cleanly off the native app without touching Play or the server', async () => {
        native.isNative = false;

        const error = await buyOnPlay(args).catch((e) => e);

        expect(error).toBeInstanceOf(BillingError);
        expect(error.code).toBe('not_native');
        expect(NativeBilling.purchase).not.toHaveBeenCalled();
        expect(axios.post).not.toHaveBeenCalled();
        await expect(playAvailable()).resolves.toBe(false);
        await expect(playProducts(['coins_500'])).resolves.toEqual([]);
        await expect(recoverPurchases({ verifyUrl: VERIFY })).resolves.toEqual([]);
        expect(typeof onPurchaseUpdated(() => {})).toBe('function');
        expect(NativeBilling.addListener).not.toHaveBeenCalled();
    });
});

describe('recoverPurchases', () => {
    it('re-verifies every pending token and consumes only the ones the server confirmed', async () => {
        NativeBilling.getPendingPurchases.mockResolvedValueOnce({
            purchases: [
                { purchaseToken: 'tok-a', orderId: 'GPA.a', productId: 'coins_500', state: 'purchased' },
                { purchaseToken: 'tok-b', orderId: null, productId: 'plan_pro_month', state: 'pending' },
                { purchaseToken: 'tok-c', orderId: 'GPA.c', productId: 'coins_100', state: 'purchased' },
            ],
        });
        axios.post
            .mockResolvedValueOnce({ status: 200, data: { wallet: { balance: 900 } } })
            .mockResolvedValueOnce({ status: 202, data: {} })
            .mockRejectedValueOnce(httpError(503));

        const results = await recoverPurchases({ verifyUrl: VERIFY });

        expect(axios.post).toHaveBeenCalledTimes(3);
        expect(axios.post.mock.calls.map(([, body]) => body.purchase_token)).toEqual(['tok-a', 'tok-b', 'tok-c']);
        expect(NativeBilling.consume).toHaveBeenCalledTimes(1);
        expect(NativeBilling.consume).toHaveBeenCalledWith({ purchaseToken: 'tok-a' });
        expect(results.map((row) => row.status)).toEqual(['fulfilled', 'pending', 'failed']);
        expect(results[0].wallet).toEqual({ balance: 900 });
        expect(results[2].error.code).toBe('google_unavailable');
    });

    it('returns [] when Play cannot be asked or nothing is pending', async () => {
        NativeBilling.getPendingPurchases.mockRejectedValueOnce(new Error('disconnected'));
        await expect(recoverPurchases({ verifyUrl: VERIFY })).resolves.toEqual([]);
        await expect(recoverPurchases({ verifyUrl: VERIFY })).resolves.toEqual([]);
        expect(axios.post).not.toHaveBeenCalled();
    });
});

describe('products and events', () => {
    it('asks Play for de-duplicated product ids', async () => {
        const products = await playProducts(['coins_500', 'coins_500', '', 'plan_pro_month']);
        expect(NativeBilling.getProducts).toHaveBeenCalledWith({ ids: ['coins_500', 'plan_pro_month'] });
        expect(products.map((p) => p.productId)).toEqual(['coins_500', 'plan_pro_month']);
        await expect(playProducts([])).resolves.toEqual([]);
    });

    it('forwards purchaseUpdated events and can unsubscribe', async () => {
        const handler = vi.fn();
        const off = onPurchaseUpdated(handler);
        expect(NativeBilling.addListener).toHaveBeenCalledWith('purchaseUpdated', expect.any(Function));

        NativeBilling.__listeners[0]({ purchases: [{ purchaseToken: 'tok-x', state: 'purchased' }] });
        expect(handler).toHaveBeenCalledWith({ purchases: [{ purchaseToken: 'tok-x', state: 'purchased' }] });

        const handle = await NativeBilling.addListener.mock.results[0].value;
        await off();
        expect(handle.remove).toHaveBeenCalled();
    });
});
