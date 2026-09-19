import axios from '../bootstrap';
import { isNativeApp } from '../lib/native';
import { NativeBilling } from './plugins';

/**
 * Google Play Billing (Y2) — the JS side of mobile/.../BillingPlugin.java.
 *
 * Flow for one purchase: Play sheet → purchase token → POST verifyUrl (the server checks the
 * token with Google and credits the coins / activates the plan) → 200 ⇒ consume the token so
 * Play forgets it and the item can be bought again. The token is consumed ONLY after a 200:
 * a pending (202), failed or unreachable (503 / network) verification leaves it in Play's
 * queue, and recoverPurchases() re-submits it on the next app open. Off the native app every
 * function returns an empty result or rejects with a BillingError — nothing touches Play.
 */

/** Error the wallet / premium screens can show as-is (`message`), with a stable `code`. */
export class BillingError extends Error {
    constructor(message, code = 'error', { retry = false, status = null } = {}) {
        super(message);
        this.name = 'BillingError';
        this.code = code;
        /** True when trying again later may work (network / Google unavailable). */
        this.retry = retry;
        this.status = status;
    }
}

/** Play-side (plugin) error codes → what the user sees. */
const PLAY_MESSAGES = {
    cancelled: 'Purchase cancelled.',
    item_already_owned: 'You already bought this and it is still being delivered. Please wait a moment.',
    unavailable: 'Google Play is not available right now. Try again in a moment.',
    not_native: 'Google Play purchases only work inside the app.',
    error: 'Google Play could not complete the purchase. Try again.',
};

/** Server verify errors (422 `error` codes from pay.play.verify) → what the user sees. */
const VERIFY_MESSAGES = {
    unknown_product: 'This item is no longer for sale. Nothing was charged.',
    cancelled: 'Google Play cancelled this purchase.',
    consumed: 'This purchase was already used. Contact support if your coins are missing.',
    account_mismatch: 'This purchase was made with a different account.',
    order_reused: 'This purchase was already delivered.',
    gateway_unavailable: 'Google Play purchases are switched off right now.',
    token_other_account: 'This purchase belongs to another account.',
};

const PENDING_MESSAGE = 'Waiting for Google Play to confirm your payment. Your coins arrive automatically once it goes through.';
const RETRY_MESSAGE = 'Could not confirm your purchase with the server. Nothing was lost — try again in a moment.';

/** True inside the app when Google Play Billing can be used on this phone. False on the web. */
export async function playAvailable() {
    if (!isNativeApp()) return false;
    try {
        const { available } = await NativeBilling.isAvailable();
        return Boolean(available);
    } catch {
        return false;
    }
}

/**
 * Play's localised prices for these product ids: [{productId, title, price, priceMicros, currency}].
 * Ids Play does not know are simply missing from the result. [] on the web.
 */
export async function playProducts(ids) {
    if (!isNativeApp()) return [];
    const list = [...new Set((ids ?? []).map((id) => String(id ?? '').trim()).filter(Boolean))];
    if (!list.length) return [];
    const { products } = await NativeBilling.getProducts({ ids: list });
    return Array.isArray(products) ? products : [];
}

/**
 * Buy one product on Google Play and have the server deliver it.
 *
 * @returns {Promise<{status: 'fulfilled'|'pending', message: string, wallet?: object, productId: string}>}
 * @throws {BillingError} with a user-facing message; `code` 'cancelled' when the user backed out
 */
export async function buyOnPlay({ productId, accountHash, verifyUrl }) {
    if (!isNativeApp()) throw new BillingError(PLAY_MESSAGES.not_native, 'not_native');
    if (!productId || !verifyUrl) throw new BillingError(PLAY_MESSAGES.error, 'invalid');

    let purchase;
    try {
        purchase = await NativeBilling.purchase({ productId: String(productId), accountHash: accountHash ?? '' });
    } catch (error) {
        const code = String(error?.code ?? 'error');
        if (code === 'item_already_owned') {
            // Bought earlier but never delivered (app killed before verify): deliver it now.
            const recovered = await recoverPurchases({ verifyUrl });
            const match = recovered.find((row) => row.productId === productId && row.status !== 'failed');
            if (match) return match;
        }
        throw new BillingError(PLAY_MESSAGES[code] ?? PLAY_MESSAGES.error, code, { retry: code === 'unavailable' });
    }

    return verifyAndConsume(purchase, verifyUrl);
}

/**
 * Send one Play purchase to the server; consume the token only on 200.
 * Pending purchases (cash at a store) are sent too: the server keeps a pending payment (202).
 */
async function verifyAndConsume(purchase, verifyUrl) {
    const productId = purchase?.productId ?? '';
    const purchaseToken = purchase?.purchaseToken ?? '';
    if (!purchaseToken) throw new BillingError(PLAY_MESSAGES.error, 'error');

    let response;
    try {
        response = await axios.post(verifyUrl, {
            product_id: productId,
            purchase_token: purchaseToken,
            order_id: purchase.orderId ?? null,
        });
    } catch (error) {
        throw verifyError(error);
    }

    if (response.status === 202) {
        // Google has not taken the money yet: keep the token; purchaseUpdated re-submits it later.
        return { status: 'pending', message: response.data?.message ?? PENDING_MESSAGE, productId, purchaseToken };
    }

    // 200: credited on the server. Consume so Play forgets the token (a failed consume is harmless:
    // the next recoverPurchases() re-verifies — idempotent — and consumes again).
    await NativeBilling.consume({ purchaseToken }).catch(() => ({ ok: false }));

    return {
        status: 'fulfilled',
        message: response.data?.message ?? 'Purchase complete.',
        wallet: response.data?.wallet,
        productId,
        purchaseToken,
    };
}

/** Map a failed verify request to a BillingError; the token is never consumed on these paths. */
function verifyError(error) {
    const status = error?.response?.status ?? null;
    const data = error?.response?.data ?? {};

    if (status === null) {
        return new BillingError(RETRY_MESSAGE, 'network', { retry: true });
    }
    if (status === 503) {
        return new BillingError(data.message ?? 'Google Play could not be reached. Try again in a moment.', 'google_unavailable', { retry: true, status });
    }
    if (status === 409) {
        return new BillingError(data.message ?? VERIFY_MESSAGES.token_other_account, 'token_other_account', { status });
    }
    if (status === 422) {
        const code = String(data.error ?? data.code ?? 'rejected');
        return new BillingError(VERIFY_MESSAGES[code] ?? data.message ?? 'This purchase could not be verified.', code, { status });
    }
    if (status === 404) {
        return new BillingError(VERIFY_MESSAGES.gateway_unavailable, 'gateway_unavailable', { status });
    }
    return new BillingError(data.message ?? RETRY_MESSAGE, 'error', { retry: status >= 500, status });
}

let recovering = Promise.resolve([]);

/**
 * Deliver purchases Play still holds (unconsumed): after a crash, a network drop or a pending
 * payment that went through. Each token is re-verified (the server is idempotent) and consumed
 * on 200. Runs one at a time; never throws.
 *
 * @returns {Promise<Array<{productId, purchaseToken, status: 'fulfilled'|'pending'|'failed', message, wallet?, error?}>>}
 */
export function recoverPurchases({ verifyUrl } = {}) {
    if (!isNativeApp() || !verifyUrl) return Promise.resolve([]);

    recovering = recovering.catch(() => []).then(async () => {
        let purchases = [];
        try {
            ({ purchases = [] } = await NativeBilling.getPendingPurchases());
        } catch {
            return [];
        }

        const results = [];
        for (const purchase of purchases ?? []) {
            try {
                results.push(await verifyAndConsume(purchase, verifyUrl));
            } catch (error) {
                results.push({
                    status: 'failed',
                    productId: purchase?.productId ?? '',
                    purchaseToken: purchase?.purchaseToken ?? '',
                    message: error?.message ?? PLAY_MESSAGES.error,
                    error,
                });
            }
        }
        return results;
    });

    return recovering;
}

/**
 * Called when a purchase completes outside a flow (a pending payment went through). The handler
 * should call recoverPurchases(). Returns a function that removes the listener; a no-op on the web.
 */
export function onPurchaseUpdated(handler) {
    if (!isNativeApp() || typeof handler !== 'function') return () => {};
    const handle = NativeBilling.addListener('purchaseUpdated', (data) => handler(data ?? {}));
    return () => Promise.resolve(handle).then((h) => h?.remove?.()).catch(() => {});
}
