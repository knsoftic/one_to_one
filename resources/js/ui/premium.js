import axios from '../bootstrap';
import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';

/** "Renew" is offered from this many days before the plan ends (same as PremiumController). */
export const RENEW_DAYS = 7;

const METHODS = {
    manual: { label: 'Bank / JazzCash / EasyPaisa', text: 'Transfer the amount and upload a screenshot.', icon: 'banknote' },
    stripe: { label: 'Card', text: 'Visa, Mastercard and more, on a secure page.', icon: 'credit-card' },
    paypal: { label: 'PayPal', text: 'Pay in US dollars with your PayPal account.', icon: 'circle-dollar-sign' },
    play: { label: 'Continue with Google Play', text: 'Billed to your Google account.', icon: 'shopping-bag' },
};

const LIMIT_LABELS = {
    upload_mb: (v) => `Send files up to ${v} MB`,
    group_members: (v) => `Groups of up to ${Number(v).toLocaleString()} people`,
    broadcast_recipients: (v) => `Broadcast to ${Number(v).toLocaleString()} people`,
    storage_mb: (v) => `${v >= 1024 ? `${Math.round(v / 1024)} GB` : `${v} MB`} of storage`,
};

const dateFormat = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
export const formatDate = (iso) => (iso ? dateFormat.format(new Date(iso)) : '');

const uuid = () => (globalThis.crypto?.randomUUID ? globalThis.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`);

/** The benefit lines of a plan or a subscription snapshot: [{text, on}]. */
export function benefitLines(benefits = {}) {
    const lines = [
        { text: 'No ads', on: Boolean(benefits.ads_off) },
        { text: 'Verified badge', on: Boolean(benefits.verified_badge) },
        { text: `${Number(benefits.monthly_coins ?? 0).toLocaleString()} free coins every month`, on: Number(benefits.monthly_coins ?? 0) > 0 },
    ];
    for (const [key, value] of Object.entries(benefits.limits ?? {})) {
        if (LIMIT_LABELS[key] && Number(value) > 0) lines.push({ text: LIMIT_LABELS[key](Number(value)), on: true });
    }
    return lines;
}

/**
 * Settings → Premium (Y2): the current plan, the plans on sale, the verified badge, and buying
 * through whichever payment methods the server offers this platform.
 */
export class Premium {
    constructor(root, config) {
        this.root = root;
        this.config = config ?? {};
        this.routes = this.config.routes ?? {};
        this.paid = this.config.paid ?? {};
        this.data = null;
        this.loading = false;
        this.busy = false;
        this.playPrices = {};
        this.manual = null;

        root.addEventListener('click', (event) => this.onClick(event));
        root.addEventListener('submit', (event) => {
            const form = event.target.closest('[data-premium-proof]');
            if (!form) return;
            event.preventDefault();
            this.sendProof(form);
        });
    }

    get android() {
        return this.paid.platform === 'android';
    }

    /* ------------------------------------------------------------------ */
    /* Loading                                                             */
    /* ------------------------------------------------------------------ */

    async load({ silent = false } = {}) {
        if (this.loading || !this.routes.premium) return;
        this.loading = true;
        if (!this.data && !silent) this.root.innerHTML = '<div class="premium-loading"><span class="spinner"></span></div>';
        try {
            const { data } = await axios.get(this.routes.premium);
            this.data = data;
            this.manual = null;
            await this.loadPlayPrices();
            this.render();
        } catch (error) {
            this.root.innerHTML = html`<div class="premium-error"><p>${errorMessage(error, "Couldn't load the plans.")}</p><button type="button" class="btn btn-secondary btn-sm" data-premium-retry>Try again</button></div>`;
        } finally {
            this.loading = false;
        }
    }

    /** In the app, prices come from Google Play (the localised string), never from the admin's list. */
    async loadPlayPrices() {
        if (!this.android || !this.data?.play?.enabled) return;
        const ids = (this.data.plans ?? []).map((plan) => plan.play_product_id).filter(Boolean);
        if (!ids.length) return;
        try {
            const { playProducts } = await import('../native/billing');
            for (const product of await playProducts(ids)) this.playPrices[product.productId] = product.price;
        } catch {
            /* Play not available: the cards show no price until it is */
        }
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                           */
    /* ------------------------------------------------------------------ */

    render() {
        const { active, queued, badge, refund_url: refundUrl } = this.data;
        const plans = this.purchasablePlans();

        this.root.innerHTML = html`
            ${raw(active ? this.currentCard(active, queued) : '')}
            ${raw(this.manual ? this.manualPanel(this.manual) : this.planCards(plans, active, queued))}
            ${raw(this.badgeCard(badge))}
            ${raw(refundUrl ? html`<p class="wa-group-note premium-refunds">Prices include taxes where they apply. <a class="wa-link" href="${refundUrl}" target="_blank" rel="noopener">Refund policy</a></p>` : '')}
        `;
    }

    /** In the app only plans sold on Google Play can be bought; on the web every active plan. */
    purchasablePlans() {
        const plans = this.data?.plans ?? [];
        return this.android ? plans.filter((plan) => plan.play_product_id) : plans;
    }

    currentCard(active, queued) {
        return html`
            <div class="premium-current" data-premium-current>
                <div class="premium-current-head">
                    <span class="premium-current-icon">${raw(icon('crown'))}</span>
                    <span class="premium-current-body">
                        <span class="premium-current-title">${active.plan} plan</span>
                        <span class="premium-current-text">Until ${formatDate(active.ends_at)}</span>
                    </span>
                    ${raw(active.renew_available ? html`<button type="button" class="btn btn-primary btn-sm" data-premium-renew="${active.plan_id}">Renew</button>` : '')}
                </div>
                <ul class="premium-benefits">${raw(this.benefitList(active.benefits, true))}</ul>
                ${raw(queued ? html`<p class="premium-queued" data-premium-queued>${raw(icon('clock'))} Next: <strong>${queued.plan}</strong> starts on ${formatDate(queued.starts_at)}.</p>` : '')}
            </div>
        `;
    }

    planCards(plans, active, queued) {
        if (!plans.length) {
            return html`<div class="premium-empty">${raw(icon('crown'))}<p>${this.android ? 'No plans are available in the app right now.' : 'No plans are on sale right now.'}</p></div>`;
        }
        return html`
            <h3 class="wa-group-title">${active ? 'Change or extend your plan' : 'Choose a plan'}</h3>
            <div class="premium-plans" data-premium-plans>
                ${raw(plans.map((plan) => this.planCard(plan, active, queued)).join(''))}
            </div>
        `;
    }

    planCard(plan, active, queued) {
        const isCurrent = active && Number(active.plan_id) === Number(plan.id);
        const isQueued = queued && Number(queued.plan_id) === Number(plan.id);
        const period = plan.period === 'year' ? 'year' : 'month';
        const price = this.android ? this.playPrices[plan.play_product_id] ?? '' : plan.price_display;

        let action;
        if (isQueued) action = html`<span class="premium-plan-state">Starts on ${formatDate(queued.starts_at)}</span>`;
        else if (isCurrent && active.renew_available) action = html`<button type="button" class="btn btn-primary" data-premium-choose="${plan.id}" data-premium-renew="${plan.id}">Renew</button>`;
        else if (isCurrent) action = html`<span class="premium-plan-state">${raw(icon('check'))} Current plan</span>`;
        else action = html`<button type="button" class="btn btn-primary" data-premium-choose="${plan.id}">Choose</button>`;

        return html`
            <article class="premium-plan ${isCurrent ? 'is-current' : ''}" data-premium-plan="${plan.id}">
                <div class="premium-plan-head">
                    <span class="premium-plan-name">${plan.name}</span>
                    ${raw(price ? html`<span class="premium-plan-price">${price}<small>/ ${period}</small></span>` : html`<span class="premium-plan-price is-pending"><small>${this.android ? 'Price on Google Play' : ''}</small></span>`)}
                </div>
                ${raw(plan.description ? html`<p class="premium-plan-text">${plan.description}</p>` : '')}
                <ul class="premium-benefits">${raw(this.benefitList(plan.benefits, false))}</ul>
                <div class="premium-plan-action">${raw(action)}</div>
            </article>
        `;
    }

    /** Ticks for what the plan includes; a plan card also lists what it leaves out, greyed. */
    benefitList(benefits, onlyIncluded) {
        return benefitLines(benefits)
            .filter((line) => line.on || !onlyIncluded)
            .map((line) => html`<li class="premium-benefit ${line.on ? 'is-on' : 'is-off'}">${raw(icon(line.on ? 'check' : 'x'))}<span>${line.text}</span></li>`)
            .join('');
    }

    badgeCard(badge) {
        if (!badge) return '';
        const days = Number(badge.days ?? 0);
        const price = Number(badge.price ?? 0);
        const period = days > 0 ? (days % 365 === 0 ? `${days / 365 === 1 ? '1 year' : `${days / 365} years`}` : `${days} days`) : 'for good';
        const included = badge.verified && badge.source === 'plan';

        let text;
        if (badge.lifetime) text = 'You are verified for good.';
        else if (included) text = 'Included in your plan.';
        else if (badge.verified) text = `Verified until ${formatDate(badge.until)}.`;
        else text = 'A tick next to your name so people know it is really you.';

        let button = '';
        if (badge.purchasable && !badge.lifetime) {
            const label = badge.verified ? 'Extend' : 'Get verified';
            button = html`<button type="button" class="btn ${badge.verified ? 'btn-secondary' : 'btn-primary'} btn-sm" data-premium-badge-buy>${label} · ${price.toLocaleString()} coins</button>`;
        }

        return html`
            <h3 class="wa-group-title">Verified badge</h3>
            <div class="premium-badge ${badge.verified ? 'is-on' : ''}" data-premium-badge data-badge-source="${badge.source ?? ''}">
                <span class="premium-badge-icon">${raw(icon('badge-check'))}</span>
                <span class="premium-badge-body">
                    <span class="premium-badge-title">${badge.verified ? 'You are verified' : 'Get verified'}</span>
                    <span class="premium-badge-text">${text}${raw(!badge.lifetime && badge.purchasable ? html` <span class="premium-badge-price">${price.toLocaleString()} coins · ${period}</span>` : '')}</span>
                    <span class="premium-badge-error" data-premium-badge-error hidden></span>
                </span>
                ${raw(button)}
            </div>
        `;
    }

    /* ------------------------------------------------------------------ */
    /* Buying a plan                                                       */
    /* ------------------------------------------------------------------ */

    onClick(event) {
        if (event.target.closest('[data-premium-retry]')) return this.load();
        const choose = event.target.closest('[data-premium-choose], [data-premium-renew]');
        if (choose) return this.choose(Number(choose.dataset.premiumChoose ?? choose.dataset.premiumRenew));
        if (event.target.closest('[data-premium-badge-buy]')) return this.buyBadge();
        if (event.target.closest('[data-premium-manual-back]')) {
            this.manual = null;
            return this.render();
        }
        const wallet = event.target.closest('[data-premium-wallet]');
        if (wallet) {
            event.preventDefault();
            return this.openWallet();
        }
        return null;
    }

    async choose(planId) {
        const plan = (this.data?.plans ?? []).find((row) => Number(row.id) === Number(planId));
        if (!plan || this.busy) return;
        const methods = (this.data.methods ?? []).filter((key) => METHODS[key]);
        if (!methods.length) {
            toast('No payment method is available right now.', { type: 'info' });
            return;
        }
        const gateway = methods.length === 1 && this.android ? methods[0] : await this.pickMethod(plan, methods);
        if (!gateway) return;
        if (gateway === 'play') await this.buyOnPlay(plan);
        else await this.begin(plan, gateway);
    }

    /** The method sheet is built only from what the server offered for this platform. */
    pickMethod(plan, methods) {
        return new Promise((resolve) => {
            const previous = document.activeElement;
            const sheet = document.createElement('div');
            sheet.className = 'modal premium-sheet';
            sheet.setAttribute('role', 'dialog');
            sheet.setAttribute('aria-modal', 'true');
            sheet.setAttribute('aria-labelledby', 'premium-sheet-title');
            const price = this.android ? this.playPrices[plan.play_product_id] ?? '' : plan.price_display;
            sheet.innerHTML = html`
                <div class="modal-backdrop" data-sheet-cancel></div>
                <div class="modal-panel premium-sheet-panel" data-premium-methods>
                    <h2 class="modal-title" id="premium-sheet-title">${plan.name} plan${price ? ` · ${price}` : ''}</h2>
                    <p class="modal-text">How would you like to pay?</p>
                    <div class="premium-methods">
                        ${raw(methods.map((key) => html`
                            <button type="button" class="premium-method" data-premium-method="${key}">
                                <span class="premium-method-icon">${raw(icon(METHODS[key].icon))}</span>
                                <span class="premium-method-body">
                                    <span class="premium-method-title">${METHODS[key].label}</span>
                                    <span class="premium-method-text">${METHODS[key].text}</span>
                                </span>
                                ${raw(icon('chevron-right', 'premium-method-chevron'))}
                            </button>`).join(''))}
                    </div>
                    <div class="modal-actions"><button type="button" class="btn btn-secondary" data-sheet-cancel>Cancel</button></div>
                </div>
            `;
            const close = (value) => {
                sheet.remove();
                document.removeEventListener('keydown', onKey);
                previous?.focus?.();
                resolve(value);
            };
            const onKey = (e) => {
                if (e.key === 'Escape') close(null);
            };
            sheet.addEventListener('click', (e) => {
                if (e.target.closest('[data-sheet-cancel]')) close(null);
                const method = e.target.closest('[data-premium-method]');
                if (method) close(method.dataset.premiumMethod);
            });
            document.addEventListener('keydown', onKey);
            document.body.appendChild(sheet);
            sheet.querySelector('[data-premium-method]')?.focus();
        });
    }

    /** Web: start a payment; a hosted page redirects, a manual transfer shows the instructions. */
    async begin(plan, gateway) {
        if (!this.routes.payBegin) return;
        this.busy = true;
        this.setBusy(true);
        try {
            const { data } = await axios.post(this.routes.payBegin, {
                purpose: 'plan', item_id: plan.id, gateway, client_token: uuid(),
            });
            if (data.redirect) {
                window.location.assign(data.redirect);
                return;
            }
            if (data.instructions) {
                this.manual = { plan, payment: data.payment, instructions: data.instructions, sent: false };
                this.render();
                this.root.querySelector('[data-premium-manual]')?.scrollIntoView?.({ block: 'start' });
                return;
            }
            toast('Payment started.', { type: 'info' });
            await this.load({ silent: true });
        } catch (error) {
            toast(errorMessage(error, "Couldn't start the payment."), { type: 'error' });
        } finally {
            this.busy = false;
            this.setBusy(false);
        }
    }

    /** Android: Google Play's sheet, then the server verifies and activates the plan. */
    async buyOnPlay(plan) {
        const minAppCode = this.data?.play?.minAppCode ?? this.paid.play?.minAppCode;
        const build = Number(this.config.native?.build ?? 0);
        if (minAppCode && build && build < minAppCode) {
            toast('Update the app to buy a plan.', { type: 'info' });
            return;
        }
        this.busy = true;
        this.setBusy(true);
        try {
            const { buyOnPlay } = await import('../native/billing');
            const result = await buyOnPlay({
                productId: plan.play_product_id,
                accountHash: this.data?.play?.accountHash ?? this.paid.play?.accountHash,
                verifyUrl: this.routes.payPlayVerify,
            });
            toast(result?.message ?? (result?.status === 'pending' ? 'Waiting for Google Play.' : `Your ${plan.name} plan is active.`), { type: result?.status === 'fulfilled' ? 'success' : 'info' });
            await this.load({ silent: true });
        } catch (error) {
            if (error?.code !== 'cancelled') toast(error?.message ?? 'Google Play is not available right now.', { type: 'error' });
        } finally {
            this.busy = false;
            this.setBusy(false);
        }
    }

    manualPanel({ plan, payment, instructions, sent }) {
        const methods = Object.entries(instructions.methods ?? {});
        const amount = instructions.amount_display ?? (instructions.amount != null ? `${instructions.currency ?? ''} ${(Number(instructions.amount) / 100).toLocaleString()}` : plan.price_display);
        const proofUrl = (this.routes.payProof ?? '').replace('__ID__', String(payment?.id ?? ''));

        return html`
            <div class="premium-manual" data-premium-manual data-payment-id="${payment?.id ?? ''}">
                <div class="premium-manual-head">
                    <button type="button" class="btn-icon" data-premium-manual-back aria-label="Back to plans">${raw(icon('arrow-left'))}</button>
                    <span class="premium-manual-title">Pay ${amount} for ${plan.name}</span>
                </div>
                <p class="premium-manual-text">Send the amount with one of these, then upload a screenshot of the transfer. Your plan starts once we have checked it (usually within a day).</p>
                <div class="premium-manual-methods">
                    ${raw(methods.map(([key, method]) => html`
                        <div class="premium-manual-method" data-manual-method="${key}">
                            <span class="premium-manual-method-title">${method.label ?? key}</span>
                            <pre class="premium-manual-method-text">${method.text ?? ''}</pre>
                        </div>`).join(''))}
                </div>
                ${raw(instructions.note ? html`<p class="wa-group-note">${instructions.note}</p>` : '')}
                ${raw(sent ? html`
                    <div class="premium-manual-sent">${raw(icon('clock'))}<span>Screenshot received. We will check it and turn your plan on — you will get a notification.</span></div>
                ` : html`
                    <form class="premium-proof" data-premium-proof action="${proofUrl}" method="post" enctype="multipart/form-data">
                        <label class="premium-proof-field">
                            <span>Paid with</span>
                            <select name="method" class="form-control" required>
                                ${raw(methods.map(([key, method]) => html`<option value="${key}">${method.label ?? key}</option>`).join(''))}
                            </select>
                        </label>
                        <label class="premium-proof-field">
                            <span>Transaction id</span>
                            <input name="ref" class="form-control" maxlength="64" required placeholder="From the receipt">
                        </label>
                        <label class="premium-proof-field">
                            <span>Note <small>(optional)</small></span>
                            <input name="note" class="form-control" maxlength="160" placeholder="Sender name or anything that helps">
                        </label>
                        <label class="premium-proof-field">
                            <span>Screenshot</span>
                            <input type="file" name="screenshot" class="form-control" accept="image/png,image/jpeg,image/webp" required>
                        </label>
                        <div class="premium-proof-actions">
                            <button type="submit" class="btn btn-primary">${raw(icon('upload'))} Send screenshot</button>
                        </div>
                    </form>
                `)}
            </div>
        `;
    }

    async sendProof(form) {
        const url = form.getAttribute('action');
        if (this.busy || !url) return;
        this.busy = true;
        const button = form.querySelector('button[type="submit"]');
        if (button) button.disabled = true;
        try {
            await axios.post(url, new FormData(form), { headers: { 'Content-Type': 'multipart/form-data' } });
            if (this.manual) this.manual.sent = true;
            toast('Screenshot sent. We will check it soon.', { type: 'success' });
            this.render();
        } catch (error) {
            toast(errorMessage(error, "Couldn't send the screenshot."), { type: 'error' });
        } finally {
            this.busy = false;
            if (button) button.disabled = false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Verified badge                                                      */
    /* ------------------------------------------------------------------ */

    async buyBadge() {
        const badge = this.data?.badge;
        if (!badge || this.busy || !this.routes.badgeBuy) return;
        const price = Number(badge.price ?? 0).toLocaleString();
        const days = Number(badge.days ?? 0);
        const period = days > 0 ? `for ${days % 365 === 0 ? `${days / 365 === 1 ? 'a year' : `${days / 365} years`}` : `${days} days`}` : 'for good';
        const choice = await confirmDialog({
            title: badge.verified ? 'Extend your verified badge?' : 'Get verified?',
            message: `${price} coins ${period}${badge.verified && !badge.lifetime ? ', added after your current badge' : ''}.`,
            icon: 'badge-check',
            tone: 'primary',
            actions: [{ label: badge.verified ? 'Extend' : 'Buy', value: 'buy', variant: 'primary' }],
        });
        if (choice !== 'buy') return;

        this.busy = true;
        const errorBox = this.root.querySelector('[data-premium-badge-error]');
        try {
            await axios.post(this.routes.badgeBuy, { client_token: uuid() });
            toast('You are verified.', { type: 'success' });
            await this.load({ silent: true });
        } catch (error) {
            const body = error?.response?.data ?? {};
            if (errorBox && (body.code === 'insufficient_coins' || error?.response?.status === 422)) {
                const short = body.code === 'insufficient_coins' && body.needed != null
                    ? `You need ${Number(body.needed).toLocaleString()} more coins.`
                    : errorMessage(error, "Couldn't buy the badge.");
                errorBox.innerHTML = html`${short} <a href="#" class="wa-link" data-premium-wallet>Get coins</a>`;
                errorBox.hidden = false;
            } else {
                toast(errorMessage(error, "Couldn't buy the badge."), { type: 'error' });
            }
        } finally {
            this.busy = false;
        }
    }

    /** The Wallet section of the settings page, when it is on this page. */
    openWallet() {
        const opener = document.querySelector('[data-settings-open="wallet"]');
        if (opener) opener.click();
        else if (this.routes.settings) window.location.assign(`${this.routes.settings}?tab=wallet`);
    }

    setBusy(busy) {
        for (const button of this.root.querySelectorAll('[data-premium-choose], [data-premium-renew]')) button.disabled = busy;
    }
}

export function initPremium(config, root = document.querySelector('[data-premium]')) {
    if (!root || !config?.routes?.premium) return null;
    const premium = new Premium(root, config);
    const section = root.closest('[data-settings-section]');
    const start = () => {
        if (!premium.data && !premium.loading) premium.load();
        else if (premium.data && !premium.manual) premium.load({ silent: true });
    };

    if (!section || section.classList.contains('is-active')) start();
    document.addEventListener('settings:section', (event) => {
        if (event.detail?.name === section?.dataset.settingsSection) start();
    });
    return premium;
}
