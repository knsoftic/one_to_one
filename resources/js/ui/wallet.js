import axios from '../bootstrap';
import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';

/**
 * Settings › Wallet (Y2): the balance, what was earned and bought, payments still in progress,
 * coin packs with the ways to pay for them, and the ledger.
 *
 * Everything money-related comes from the server: the pack list, the payment methods (built ONLY
 * from `payload.methods`, so the Android app never sees web methods) and the pending payments.
 * The web bundle never talks to Google Play; the Play path is a dynamic import used only when
 * `config.paid.platform === 'android'`.
 */

const POLL_EVERY_MS = 3_000;
const POLL_FOR_MS = 120_000;

const METHODS = {
    manual: { icon: 'banknote', title: 'Bank or mobile wallet', text: 'JazzCash, EasyPaisa or bank transfer — send a screenshot' },
    stripe: { icon: 'credit-card', title: 'Card', text: 'Visa or Mastercard, secure checkout' },
    paypal: { icon: 'circle-dollar-sign', title: 'PayPal', text: 'Pay in US dollars' },
    play: { icon: 'shopping-bag', title: 'Google Play', text: 'Pay with your Google Play account' },
};

const MANUAL_METHODS = { jazzcash: 'JazzCash', easypaisa: 'EasyPaisa', bank: 'Bank transfer' };

const TYPE_ICONS = {
    purchase: 'shopping-bag', plan_coins: 'crown', referral: 'gift', referral_welcome: 'party-popper', referral_void: 'undo-2',
    promotion_hold: 'megaphone', promotion_refund: 'undo-2', badge: 'badge-check', payment_refund: 'undo-2', admin_adjust: 'hand-coins',
};

const dateTime = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' });
const formatDate = (value) => (value ? dateTime.format(new Date(value)) : '');
const coins = (n) => Number(n ?? 0).toLocaleString();
const withId = (template, id) => String(template ?? '').replace('__ID__', String(id));
const uuid = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

export class Wallet {
    constructor(root, config) {
        this.root = root;
        this.config = config ?? {};
        this.routes = this.config.routes ?? {};
        this.paid = this.config.paid ?? {};
        this.data = null;
        this.loading = false;
        this.busy = false;
        this.sheetPack = null;
        this.instructions = {};
        this.polls = new Map();
        this.history = { rows: [], page: 1, hasMore: false, loading: false };

        root.addEventListener('click', (event) => this.onClick(event));
        root.addEventListener('submit', (event) => this.onSubmit(event));
    }

    /* ------------------------------------------------------------------ data */

    async load({ silent = false } = {}) {
        if (this.loading) return;
        this.loading = true;
        if (!this.data && !silent) this.root.innerHTML = '<div class="wa-loading"><span class="spinner"></span> Loading…</div>';

        try {
            const { data } = await axios.get(this.routes.wallet ?? this.root.dataset.route);
            this.data = data;
            this.history = { rows: data.history?.data ?? [], page: data.history?.page ?? 1, hasMore: Boolean(data.history?.has_more), loading: false };
            this.render();
            this.updateChip();
            this.startPolling();
        } catch (error) {
            this.root.innerHTML = html`<div class="wallet-error">${raw(icon('circle-alert'))}<p>${errorMessage(error, "Couldn't load your wallet.")}</p><button type="button" class="btn btn-secondary btn-sm" data-wallet-retry>Try again</button></div>`;
        } finally {
            this.loading = false;
        }
    }

    updateChip() {
        const chip = document.querySelector('[data-wallet-chip]');
        if (chip && this.data) chip.textContent = coins(this.data.summary?.balance);
    }

    /* ---------------------------------------------------------------- render */

    render() {
        const { summary = {}, packs = [], pending = [], refund_url: refundUrl } = this.data;
        const withdraw = Boolean(this.paid.withdraw || summary.withdraw_enabled);

        this.root.innerHTML = html`
            <div class="wallet-hero">
                <span class="wallet-hero-label">Balance</span>
                <div class="wallet-balance" data-wallet-balance>${raw(icon('coins'))}<span>${coins(summary.balance)}</span></div>
                <p class="wallet-hero-note">Coins pay for promotions and the verified badge.</p>
                ${summary.frozen ? raw(html`<span class="wallet-frozen">${raw(icon('lock'))} Your wallet is on hold — contact support.</span>`) : ''}
            </div>
            <div class="wallet-tiles">
                <div class="wallet-tile" data-wallet-earned>
                    <span class="wallet-tile-label">Earned</span>
                    <span class="wallet-tile-value">${coins(summary.withdrawable)}</span>
                    <span class="wallet-tile-sub">${coins(summary.earned_total)} earned in total</span>
                    <button type="button" class="btn btn-secondary btn-sm ${withdraw ? '' : 'is-disabled'}" data-wallet-withdraw aria-disabled="${withdraw ? 'false' : 'true'}">
                        ${raw(icon('banknote'))} ${withdraw ? 'Withdraw' : 'Withdraw · Coming soon'}
                    </button>
                </div>
                <div class="wallet-tile" data-wallet-purchased>
                    <span class="wallet-tile-label">Purchased</span>
                    <span class="wallet-tile-value">${coins(summary.purchased)}</span>
                    <span class="wallet-tile-sub">${coins(summary.purchased_total)} bought in total</span>
                </div>
            </div>

            ${raw(pending.map((payment) => this.pendingHtml(payment)).join(''))}

            <div class="wa-group">
                <h3 class="wa-group-title">Buy coins</h3>
                ${packs.length
                    ? raw(html`<div class="wallet-packs">${raw(packs.map((pack) => this.packHtml(pack)).join(''))}</div>`)
                    : raw('<p class="wallet-empty">No coin packs are on sale right now.</p>')}
                <div data-wallet-sheet></div>
                <div class="wallet-links">
                    ${refundUrl ? raw(html`<a href="${refundUrl}" target="_blank" rel="noopener">Refund policy</a>`) : ''}
                </div>
            </div>

            <div class="wa-group">
                <h3 class="wa-group-title">History</h3>
                <div class="wallet-history" data-wallet-history></div>
                <div class="wallet-more" data-wallet-more></div>
            </div>
        `;

        this.renderHistory();
        if (this.sheetPack) this.renderSheet(this.sheetPack);
    }

    packHtml(pack) {
        const android = this.paid.platform === 'android';
        const price = android ? (pack.play_price ?? 'Google Play') : pack.price_display;
        const canBuy = (this.data.methods ?? []).length > 0 && (!android || pack.play_product_id);

        return html`
            <button type="button" class="wallet-pack" data-wallet-pack="${pack.id}" ${raw(canBuy ? '' : 'disabled')}>
                <span class="wallet-pack-coins">${coins(pack.coins)}</span>
                ${pack.bonus_coins > 0 ? raw(html`<span class="wallet-pack-bonus">+${coins(pack.bonus_coins)}</span>`) : ''}
                <span class="wallet-pack-price">${price}</span>
                <span class="wallet-pack-name">${pack.name}</span>
            </button>`;
    }

    pendingHtml(payment) {
        const manual = payment.gateway === 'manual';
        const title = `${payment.item} · ${payment.amount_display}`;
        let body;

        if (payment.status === 'review' || (manual && payment.has_proof)) {
            body = html`<span class="wallet-pending-text">Waiting for review — we check transfers within a day.${payment.proof_ref ? ` Transaction ${payment.proof_ref}.` : ''}</span>`;
        } else if (manual) {
            const info = this.instructions[payment.id];
            body = html`
                <span class="wallet-pending-text">Send ${payment.amount_display} using one of the methods below, then upload your screenshot.</span>
                ${info ? raw(this.instructionsHtml(info)) : raw(html`<button type="button" class="btn btn-secondary btn-sm" data-wallet-instructions="${payment.id}">Show payment details</button>`)}
                <form class="wallet-proof-form" data-wallet-proof="${payment.id}" enctype="multipart/form-data">
                    <select name="method" class="form-control" required aria-label="How you paid">
                        ${raw(Object.entries(info?.methods ?? MANUAL_METHODS).map(([key, value]) => html`<option value="${key}">${typeof value === 'string' ? value : value.label}</option>`).join(''))}
                    </select>
                    <input name="ref" class="form-control" maxlength="64" required placeholder="Transaction ID" aria-label="Transaction ID">
                    <input name="note" class="form-control" maxlength="160" placeholder="Note (optional)" aria-label="Note">
                    <input name="screenshot" type="file" class="form-control" accept="image/*" required aria-label="Screenshot">
                    <div class="wallet-pending-actions">
                        <button type="submit" class="btn btn-primary btn-sm">${raw(icon('upload'))} Upload screenshot</button>
                        <button type="button" class="btn btn-secondary btn-sm" data-wallet-cancel="${payment.id}">Cancel</button>
                    </div>
                </form>`;
        } else if (payment.status === 'paid') {
            body = html`<span class="wallet-pending-text">Paid — adding your coins…</span>`;
        } else if (payment.gateway === 'play') {
            body = html`<span class="wallet-pending-text">Waiting for Google Play to confirm the purchase.</span>`;
        } else {
            body = html`
                <span class="wallet-pending-text">Confirming your payment… <span class="spinner spinner-sm"></span></span>
                <div class="wallet-pending-actions">
                    ${payment.redirect ? raw(html`<a class="btn btn-primary btn-sm" href="${payment.redirect}">Continue payment</a>`) : ''}
                    <button type="button" class="btn btn-secondary btn-sm" data-wallet-cancel="${payment.id}">Cancel</button>
                </div>`;
        }

        return html`
            <div class="wallet-pending" data-wallet-pending="${payment.id}" data-status="${payment.status}">
                ${raw(icon('clock'))}
                <div class="wallet-pending-body">
                    <span class="wallet-pending-title">${title}</span>
                    ${raw(body)}
                </div>
            </div>`;
    }

    instructionsHtml(info) {
        const methods = Object.entries(info.methods ?? {});
        return html`
            <div class="wallet-instructions">
                ${raw(methods.map(([key, value]) => html`<p><strong>${typeof value === 'string' ? MANUAL_METHODS[key] ?? key : value.label}</strong>${typeof value === 'string' ? value : value.text}</p>`).join(''))}
                ${info.note ? raw(html`<p>${info.note}</p>`) : ''}
                <p><strong>Amount</strong>${info.amount_display ?? ''}</p>
            </div>`;
    }

    renderSheet(pack) {
        const sheet = this.root.querySelector('[data-wallet-sheet]');
        if (!sheet) return;
        if (!pack) {
            sheet.innerHTML = '';
            return;
        }
        // The list comes from the server for this platform: the Android app only ever gets 'play'.
        const methods = (this.data.methods ?? []).filter((key) => METHODS[key]);
        sheet.innerHTML = html`
            <div class="wallet-sheet-title">
                <span>Pay for ${coins(pack.total_coins ?? pack.coins)} coins · ${pack.price_display}</span>
                <button type="button" class="btn-icon btn-icon-sm" data-wallet-sheet-close aria-label="Close">${raw(icon('x'))}</button>
            </div>
            <div class="wallet-methods">
                ${raw(methods.map((key) => html`
                    <button type="button" class="wallet-method" data-wallet-method="${key}">
                        ${raw(icon(METHODS[key].icon))}
                        <span class="wallet-method-body">
                            <span class="wallet-method-title">${METHODS[key].title}</span>
                            <span class="wallet-method-text">${METHODS[key].text}</span>
                        </span>
                        ${raw(icon('arrow-right'))}
                    </button>`).join(''))}
                ${methods.length ? '' : raw('<p class="wallet-empty">No payment method is available right now.</p>')}
            </div>`;
    }

    renderHistory() {
        const list = this.root.querySelector('[data-wallet-history]');
        const more = this.root.querySelector('[data-wallet-more]');
        if (!list) return;

        list.innerHTML = this.history.rows.length
            ? this.history.rows.map((row) => this.txnHtml(row)).join('')
            : '<p class="wallet-empty">Nothing yet. Coins you buy or earn show up here.</p>';

        more.innerHTML = this.history.hasMore
            ? html`<button type="button" class="btn btn-secondary btn-sm" data-wallet-load-more ${raw(this.history.loading ? 'disabled' : '')}>${this.history.loading ? 'Loading…' : 'Load more'}</button>`
            : '';
    }

    txnHtml(row) {
        const credit = row.amount > 0;
        return html`
            <div class="wallet-txn" data-wallet-txn="${row.id}">
                <span class="wallet-txn-icon">${raw(icon(TYPE_ICONS[row.type] ?? 'history'))}</span>
                <span class="wallet-txn-body">
                    <span class="wallet-txn-title">${row.label}</span>
                    <span class="wallet-txn-meta">${formatDate(row.created_at)}${row.note ? ` · ${row.note}` : ''}</span>
                </span>
                <span class="wallet-txn-amount ${credit ? 'is-credit' : 'is-debit'}">${credit ? '+' : '−'}${coins(Math.abs(row.amount))}</span>
            </div>`;
    }

    /* --------------------------------------------------------------- actions */

    onClick(event) {
        const t = event.target;
        if (t.closest('[data-wallet-retry]')) return this.load();
        if (t.closest('[data-wallet-withdraw]')) return this.withdraw();
        if (t.closest('[data-wallet-load-more]')) return this.loadMore();
        if (t.closest('[data-wallet-sheet-close]')) return this.openSheet(null);

        const pack = t.closest('[data-wallet-pack]');
        if (pack) return this.choosePack(this.data.packs.find((p) => String(p.id) === pack.dataset.walletPack));

        const method = t.closest('[data-wallet-method]');
        if (method && this.sheetPack) return this.begin(this.sheetPack, method.dataset.walletMethod);

        const info = t.closest('[data-wallet-instructions]');
        if (info) return this.showInstructions(Number(info.dataset.walletInstructions));

        const cancel = t.closest('[data-wallet-cancel]');
        if (cancel) return this.cancel(Number(cancel.dataset.walletCancel));
        return undefined;
    }

    onSubmit(event) {
        const form = event.target.closest('[data-wallet-proof]');
        if (!form) return;
        event.preventDefault();
        this.submitProof(Number(form.dataset.walletProof), form);
    }

    async withdraw() {
        if (!(this.paid.withdraw || this.data?.summary?.withdraw_enabled)) {
            toast('Withdrawals are coming soon.', { type: 'info' });
            return;
        }
        try {
            await axios.post(this.routes.walletWithdraw);
            toast('Withdrawal requested.', { type: 'success' });
        } catch (error) {
            const code = error?.response?.data?.code;
            toast(code === 'coming_soon' ? 'Withdrawals are coming soon.' : errorMessage(error, "Couldn't request a withdrawal."), { type: code === 'coming_soon' ? 'info' : 'error' });
        }
    }

    async loadMore() {
        if (this.history.loading || !this.history.hasMore) return;
        this.history.loading = true;
        this.renderHistory();
        try {
            const { data } = await axios.get(this.routes.walletHistory, { params: { page: this.history.page + 1 } });
            this.history.rows = this.history.rows.concat(data.data ?? []);
            this.history.page = data.page ?? this.history.page + 1;
            this.history.hasMore = Boolean(data.has_more);
        } catch (error) {
            toast(errorMessage(error, "Couldn't load more."), { type: 'error' });
        } finally {
            this.history.loading = false;
            this.renderHistory();
        }
    }

    choosePack(pack) {
        if (!pack || this.busy) return;
        const methods = this.data.methods ?? [];
        // Android: Google Play is the only way to pay, so there is nothing to choose.
        if (this.paid.platform === 'android') {
            if (methods.includes('play')) this.buyOnPlay(pack);
            else toast('Buying coins is not available in this version of the app.', { type: 'info' });
            return;
        }
        if (methods.length === 1) {
            this.begin(pack, methods[0]);
            return;
        }
        this.openSheet(pack);
    }

    openSheet(pack) {
        this.sheetPack = pack;
        this.renderSheet(pack);
    }

    async begin(pack, gateway) {
        if (this.busy) return;
        this.busy = true;
        try {
            const { data } = await axios.post(this.routes.payBegin, { purpose: 'coins', item_id: pack.id, gateway, client_token: uuid() });
            if (data.redirect) {
                window.location.assign(data.redirect);
                return;
            }
            if (data.instructions && data.payment) this.instructions[data.payment.id] = data.instructions;
            this.sheetPack = null;
            await this.load({ silent: true });
            this.root.querySelector(`[data-wallet-pending="${data.payment?.id}"]`)?.scrollIntoView?.({ block: 'nearest' });
        } catch (error) {
            toast(errorMessage(error, "Couldn't start the payment."), { type: 'error' });
        } finally {
            this.busy = false;
        }
    }

    async buyOnPlay(pack) {
        const minAppCode = this.paid.play?.minAppCode;
        const build = Number(this.config.native?.build ?? 0);
        if (minAppCode && build && build < minAppCode) {
            toast('Update the app to buy coins.', { type: 'info' });
            return;
        }
        this.busy = true;
        try {
            const { buyOnPlay } = await import('../native/billing');
            const result = await buyOnPlay({ productId: pack.play_product_id, accountHash: this.data.play?.accountHash ?? this.paid.play?.accountHash, verifyUrl: this.routes.payPlayVerify });
            toast(result.message ?? (result.status === 'pending' ? 'Waiting for Google Play.' : 'Coins added.'), { type: result.status === 'fulfilled' ? 'success' : 'info' });
            await this.load({ silent: true });
        } catch (error) {
            if (error?.code !== 'cancelled') toast(error?.message ?? 'Google Play could not complete the purchase.', { type: 'error' });
        } finally {
            this.busy = false;
        }
    }

    async showInstructions(id) {
        try {
            const { data } = await axios.get(withId(this.routes.payShow, id));
            if (data.instructions) this.instructions[id] = data.instructions;
            this.render();
        } catch (error) {
            toast(errorMessage(error, "Couldn't load the payment details."), { type: 'error' });
        }
    }

    async submitProof(id, form) {
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        try {
            await axios.post(withId(this.routes.payProof, id), new FormData(form), { headers: { 'Content-Type': 'multipart/form-data' } });
            toast('Screenshot sent — we will check it soon.', { type: 'success' });
            await this.load({ silent: true });
        } catch (error) {
            toast(errorMessage(error, "Couldn't upload the screenshot."), { type: 'error' });
            if (button) button.disabled = false;
        }
    }

    async cancel(id) {
        try {
            await axios.delete(withId(this.routes.payCancel, id));
            delete this.instructions[id];
            await this.load({ silent: true });
        } catch (error) {
            toast(errorMessage(error, "Couldn't cancel the payment."), { type: 'error' });
        }
    }

    /* --------------------------------------------------------------- polling */

    /** Stripe / PayPal payments that are pending or paid: ask the server every few seconds. */
    startPolling() {
        const watch = (this.data?.pending ?? []).filter((p) => ['stripe', 'paypal'].includes(p.gateway) && ['pending', 'paid'].includes(p.status));
        const ids = new Set(watch.map((p) => p.id));
        for (const [id, poll] of this.polls) {
            if (!ids.has(id)) {
                clearInterval(poll.timer);
                this.polls.delete(id);
            }
        }
        for (const payment of watch) {
            if (this.polls.has(payment.id)) continue;
            const poll = { started: Date.now(), timer: null };
            poll.timer = setInterval(() => this.poll(payment.id, poll), POLL_EVERY_MS);
            this.polls.set(payment.id, poll);
        }
    }

    async poll(id, poll) {
        if (Date.now() - poll.started > POLL_FOR_MS) {
            clearInterval(poll.timer);
            this.polls.delete(id);
            return;
        }
        try {
            const { data } = await axios.get(withId(this.routes.payShow, id));
            const status = data.payment?.status;
            if (status === 'fulfilled') {
                toast(`${coins(data.payment.coins)} coins added to your wallet.`, { type: 'success' });
            }
            if (status && !['pending', 'paid'].includes(status)) {
                clearInterval(poll.timer);
                this.polls.delete(id);
                await this.load({ silent: true });
            }
        } catch {
            /* try again on the next tick */
        }
    }

    stopPolling() {
        for (const poll of this.polls.values()) clearInterval(poll.timer);
        this.polls.clear();
    }
}

export function initWallet(config, root = document.querySelector('[data-wallet]')) {
    if (!root) return null;
    const wallet = new Wallet(root, config);
    const section = root.closest('[data-settings-section]');
    const start = () => {
        if (wallet.data) wallet.load({ silent: true });
        else if (!wallet.loading) wallet.load();
    };

    if (!section || section.classList.contains('is-active')) start();
    document.addEventListener('settings:section', (event) => {
        if (event.detail?.name === section?.dataset.settingsSection) start();
    });
    return wallet;
}
