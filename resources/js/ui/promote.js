import axios from '../bootstrap';
import { adCardHtml } from '../chat/ads';
import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { confirmDialog } from '../lib/modal';
import { toast } from '../lib/toast';

/**
 * Settings › Promote (Y2): "My promotions" with their stats, and the four-step wizard that
 * turns a status, channel, community, business profile, custom card or link into a
 * "Promoted" card paid with coins.
 */

export const STEPS = ['target', 'card', 'budget', 'review'];
export const PRESETS = [100, 250, 500, 1000];

/** How many views a budget buys — the same `intdiv(coins * 1000, rate)` the server uses. */
export const quoteViews = (coins, rate) => Math.floor((Math.max(0, Math.trunc(Number(coins) || 0)) * 1000) / Math.max(1, Number(rate) || 1));

const DRAFT_KEY = 'promote:draft';

const KIND_META = {
    status: { label: 'Status update', icon: 'circle-dashed', text: 'Shown to people outside your contacts', cta: 'View status' },
    channel: { label: 'Channel', icon: 'megaphone', text: 'Get more followers', cta: 'Follow channel' },
    community: { label: 'Community', icon: 'users', text: 'Get more members', cta: 'Join community' },
    business: { label: 'Business profile', icon: 'briefcase-business', text: 'People can message you', cta: 'Message' },
    card: { label: 'Custom card', icon: 'image', text: 'Your own picture, text and link', cta: 'Learn more' },
    link: { label: 'Website link', icon: 'link', text: 'A link with its preview', cta: 'Open link' },
};

const CHIP = { pending: 'is-pending', active: 'is-active', completed: 'is-done', stopped: 'is-done', rejected: 'is-rejected' };

const number = (n) => Number(n ?? 0).toLocaleString();
const shortDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : '');

const uuid = () =>
    globalThis.crypto?.randomUUID?.() ??
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });

export class Promote {
    constructor(root, config = {}) {
        this.root = root;
        this.routes = config.routes ?? {};
        this.paid = config.paid ?? {};
        this.route = root.dataset.route || this.routes.promotions;
        this.data = null;
        this.loading = false;
        this.view = 'list';
        this.detail = null;
        this.wizard = null;
        this.submitting = false;
        this.preselect = { kind: root.dataset.kind || '', id: root.dataset.target || '' };

        root.addEventListener('click', (event) => this.onClick(event));
        root.addEventListener('input', (event) => this.onInput(event));
        root.addEventListener('change', (event) => this.onChange(event));
        root.addEventListener('submit', (event) => {
            event.preventDefault();
            if (event.target.matches('[data-promote-submit-form]')) this.submit();
        });

        // Like Manage storage: load when the section is on screen, not on every settings visit.
        const section = root.closest('[data-settings-section]');
        if (!section || section.classList.contains('is-active')) this.load();
        document.addEventListener('settings:section', (event) => {
            if (event.detail?.name === 'promote' && !this.data && !this.loading) this.load();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Data                                                                */
    /* ------------------------------------------------------------------ */

    async load() {
        if (!this.route) return;
        this.loading = true;
        try {
            const { data } = await axios.get(this.route);
            this.data = data;
        } catch (error) {
            this.root.innerHTML = html`
                <div class="wa-group">
                    <p class="wa-group-note">${errorMessage(error, "Couldn't load your promotions.")}</p>
                    <div class="promote-actions"><button type="button" class="btn btn-secondary" data-promote-retry>Try again</button></div>
                </div>`;
            return;
        } finally {
            this.loading = false;
        }

        const draft = this.readDraft();
        if (draft) {
            this.startWizard(draft);
        } else if (this.preselect.kind) {
            const kind = this.preselect.kind;
            const id = this.preselect.id;
            this.preselect = { kind: '', id: '' };
            this.startWizard({ kind, targetId: id ? Number(id) : null });
        } else {
            this.renderList();
        }
    }

    async openDetail(id) {
        const route = this.routes.promotionShow?.replace('__ID__', String(id));
        if (!route) return;
        this.view = 'detail';
        this.detail = this.data?.promotions.find((row) => Number(row.id) === Number(id)) ?? null;
        this.renderDetail();
        try {
            const { data } = await axios.get(route);
            this.detail = data;
            this.renderDetail();
        } catch (error) {
            toast(errorMessage(error, "Couldn't load this promotion."), { type: 'error' });
            this.showList();
        }
    }

    async stop(id) {
        const route = this.routes.promotionStop?.replace('__ID__', String(id));
        const row = this.detail;
        if (!route || !row) return;

        const pending = row.status === 'pending';
        const choice = await confirmDialog({
            title: pending ? 'Withdraw this promotion?' : 'Stop this promotion?',
            message: pending
                ? 'It has not been reviewed yet, so every coin comes back to your wallet.'
                : 'It stops showing right away. The coins for the views you did not get come back to your wallet.',
            icon: 'circle-stop',
            actions: [{ label: pending ? 'Withdraw' : 'Stop', value: 'stop', variant: 'danger' }],
        });
        if (choice !== 'stop') return;

        try {
            const { data } = await axios.post(route);
            toast(`Stopped. ${number(data.promotion.coins_refunded)} coins are back in your wallet.`, { type: 'success' });
            if (this.data) {
                this.data.balance = data.balance;
                this.data.promotions = this.data.promotions.map((item) => (Number(item.id) === Number(id) ? data.promotion : item));
            }
            this.openDetail(id);
        } catch (error) {
            toast(errorMessage(error, "Couldn't stop the promotion."), { type: 'error' });
        }
    }

    /* ------------------------------------------------------------------ */
    /* Events                                                              */
    /* ------------------------------------------------------------------ */

    onClick(event) {
        const t = event.target;
        if (t.closest('[data-ad-card]')) event.preventDefault(); // previews never navigate
        if (t.closest('[data-promote-retry]')) return this.load();
        if (t.closest('[data-promote-new]')) return this.startWizard();
        if (t.closest('[data-promote-back]')) return this.showList();
        const open = t.closest('[data-promote-open]');
        if (open) return this.openDetail(open.dataset.promoteOpen);
        const stop = t.closest('[data-promote-stop]');
        if (stop) return this.stop(stop.dataset.promoteStop);

        if (!this.wizard) return null;
        const kind = t.closest('[data-wizard-kind]');
        if (kind) return this.chooseKind(kind.dataset.wizardKind);
        const target = t.closest('[data-wizard-target]');
        if (target) return this.chooseTarget(target.dataset.wizardTarget);
        const preset = t.closest('[data-wizard-preset]');
        if (preset) return this.setCoins(Number(preset.dataset.wizardPreset));
        if (t.closest('[data-wizard-prev]')) return this.step(-1);
        if (t.closest('[data-wizard-next]')) return this.step(1);
        if (t.closest('[data-wizard-cancel]')) return this.cancelWizard();
        if (t.closest('[data-wizard-get-coins]')) this.saveDraft();
        return null;
    }

    onInput(event) {
        const field = event.target.closest('[data-wizard-field]');
        if (!field || !this.wizard) return;
        const name = field.dataset.wizardField;
        this.wizard[name] = name === 'coins' ? Math.max(0, Math.trunc(Number(field.value) || 0)) : field.value;
        if (name === 'coins') this.renderQuote();
        else this.renderPreview();
    }

    onChange(event) {
        if (!this.wizard) return;
        const image = event.target.closest('[data-wizard-image]');
        if (image) {
            const file = image.files?.[0] ?? null;
            this.wizard.image = file && file.type.startsWith('image/') ? file : null;
            this.wizard.imageUrl = this.wizard.image ? URL.createObjectURL(this.wizard.image) : null;
            this.renderPreview();
        }
        const agree = event.target.closest('[data-wizard-agree]');
        if (agree) {
            this.wizard.agreed = agree.checked;
            this.renderReviewButton();
        }
    }

    /* ------------------------------------------------------------------ */
    /* My promotions                                                       */
    /* ------------------------------------------------------------------ */

    showList() {
        this.view = 'list';
        this.detail = null;
        this.wizard = null;
        this.renderList();
    }

    renderList() {
        const data = this.data;
        if (!data) return;
        const rows = data.promotions ?? [];
        const canAdd = !data.frozen;

        this.root.innerHTML = html`
            <div class="wa-group promote-head">
                <div class="promote-balance">
                    <span class="promote-balance-icon">${raw(icon('coins'))}</span>
                    <span class="wa-row-body">
                        <span class="wa-row-title"><strong data-promote-balance>${number(data.balance)}</strong> coins</span>
                        <span class="wa-row-text">${number(data.rates?.status ?? 100)}–${number(Math.max(...Object.values(data.rates ?? { x: 100 })))} coins per 1,000 views · people on ad-free plans don't see promotions</span>
                    </span>
                    <a class="btn btn-secondary btn-sm" href="${this.walletUrl()}">${raw(icon('wallet'))} Get coins</a>
                </div>
                <div class="promote-actions">
                    ${raw(canAdd
                        ? html`<button type="button" class="btn btn-primary" data-promote-new>${raw(icon('rocket'))} New promotion</button>`
                        : html`<p class="wa-group-note is-top">Your wallet is on hold, so you can't start a promotion right now.</p>`)}
                </div>
            </div>
            <div class="wa-group">
                <h3 class="wa-group-title">My promotions</h3>
                ${raw(rows.length ? rows.map((row) => this.rowHtml(row)).join('') : html`<p class="wa-group-note is-top">Nothing promoted yet. Boost a status, channel, community, your business or a link and it shows as a "Promoted" card in the app.</p>`)}
            </div>
        `;
    }

    rowHtml(row) {
        const pct = row.view_budget ? Math.min(100, Math.round((row.impressions / row.view_budget) * 100)) : 0;
        return html`
            <button type="button" class="wa-row is-link promote-row" data-promote-open="${row.id}">
                ${raw(row.card?.image ? html`<span class="promote-thumb" style="background-image:url('${row.card.image}')"></span>` : html`<span class="promote-thumb is-empty">${raw(icon(KIND_META[row.kind]?.icon ?? 'megaphone'))}</span>`)}
                <span class="wa-row-body">
                    <span class="wa-row-title">${row.card?.title ?? row.kind_label} <span class="promote-chip ${CHIP[row.status] ?? ''}" data-promote-chip>${row.status_label}</span></span>
                    <span class="promote-budget" aria-label="${number(row.impressions)} of ${number(row.view_budget)} views"><span class="promote-budget-bar"><span style="width:${pct}%"></span></span></span>
                    <span class="wa-row-text" data-promote-stats>${number(row.impressions)} views · ${number(row.clicks)} taps · ${row.ctr}% CTR · ${row.kind_label}</span>
                </span>
                ${raw(icon('chevron-right', 'wa-row-chevron'))}
            </button>`;
    }

    renderDetail() {
        const row = this.detail;
        if (!row) return;
        const pct = row.view_budget ? Math.min(100, Math.round((row.impressions / row.view_budget) * 100)) : 0;
        const days = row.days ?? [];
        const max = Math.max(1, ...days.map((d) => d.views));
        const card = { ...(row.card ?? {}), id: row.id, promoted: true, internal: ['status', 'channel', 'community', 'business'].includes(row.kind) };

        this.root.innerHTML = html`
            <div class="wa-group promote-detail" data-promote-detail>
                <button type="button" class="wa-row is-link" data-promote-back>${raw(icon('arrow-left', 'wa-row-icon'))}<span class="wa-row-body"><span class="wa-row-title">My promotions</span></span></button>
                <div class="promote-preview">${raw(adCardHtml(card))}</div>
                <div class="promote-status-line">
                    <span class="promote-chip ${CHIP[row.status] ?? ''}">${row.status_label}</span>
                    <span class="wa-row-text">${row.kind_label} · ${number(row.coins_spent)} coins${row.coins_refunded ? ` · ${number(row.coins_refunded)} refunded` : ''}</span>
                </div>
                ${raw(row.review_note ? html`<p class="wa-group-note promote-note">${raw(icon('circle-alert', 'icon-xs'))} Not approved: ${row.review_note}</p>` : '')}
                <div class="promote-tiles">
                    <div class="promote-tile"><strong>${number(row.impressions)}</strong><span>Views</span></div>
                    <div class="promote-tile"><strong>${number(row.clicks)}</strong><span>Taps</span></div>
                    <div class="promote-tile"><strong>${row.ctr}%</strong><span>CTR</span></div>
                </div>
                <div class="promote-budget is-wide">
                    <span class="promote-budget-bar"><span style="width:${pct}%"></span></span>
                    <span class="wa-row-text">${number(row.impressions)} of ${number(row.view_budget)} views · ${number(row.remaining)} left</span>
                </div>
            </div>
            <div class="wa-group">
                <h3 class="wa-group-title">Last 30 days</h3>
                <div class="promote-days" role="img" aria-label="Views per day">
                    ${raw(days.map((d) => html`<span class="promote-day" title="${d.label}: ${number(d.views)} views · ${number(d.taps)} taps"><span style="height:${Math.max(2, Math.round((d.views / max) * 100))}%"></span></span>`).join(''))}
                </div>
                ${raw(row.placements?.length
                    ? html`<h3 class="wa-group-title">Where it was seen</h3>${raw(row.placements.map((p) => html`<div class="wa-row"><span class="wa-row-body"><span class="wa-row-title">${p.label}</span><span class="wa-row-text">${number(p.views)} views · ${number(p.taps)} taps</span></span></div>`).join(''))}`
                    : '')}
            </div>
            ${raw(row.can_stop
                ? html`<div class="wa-group"><button type="button" class="wa-row is-danger" data-promote-stop="${row.id}">${raw(icon('circle-stop', 'wa-row-icon'))}<span class="wa-row-body"><span class="wa-row-title">${row.status === 'pending' ? 'Withdraw promotion' : 'Stop promotion'}</span><span class="wa-row-text">${row.status === 'pending' ? 'All coins come back.' : 'Unused coins come back to your wallet.'}</span></span></button></div>`
                : '')}
        `;
    }

    /* ------------------------------------------------------------------ */
    /* Wizard                                                              */
    /* ------------------------------------------------------------------ */

    startWizard(draft = {}) {
        if (!this.data) return;
        if (this.data.frozen) {
            toast("Your wallet is on hold, so you can't start a promotion right now.", { type: 'error' });
            return;
        }
        this.view = 'wizard';
        this.wizard = {
            step: 0,
            kind: '',
            targetId: null,
            title: '',
            body: '',
            cta: '',
            url: '',
            image: null,
            imageUrl: null,
            coins: PRESETS[0],
            agreed: false,
            token: uuid(),
            ...draft,
        };
        if (this.wizard.kind) {
            this.chooseKind(this.wizard.kind, { keep: true });
            // Preselected from a link (no saved step): straight to the card once the target is known.
            if (draft.step === undefined) {
                this.wizard.step = this.wizard.target || ['card', 'link'].includes(this.wizard.kind) ? 1 : 0;
            }
        }
        this.renderWizard();
    }

    cancelWizard() {
        this.clearDraft();
        this.showList();
    }

    targetsOf(kind) {
        return (this.data?.targets ?? []).filter((t) => t.kind === kind);
    }

    chooseKind(kind, { keep = false } = {}) {
        const w = this.wizard;
        if (!w || !KIND_META[kind]) return;
        if (w.kind !== kind) {
            w.kind = kind;
            w.targetId = null;
            w.title = '';
            w.body = '';
            w.cta = '';
        }
        if (!keep) w.step = 0;
        const targets = this.targetsOf(kind);
        const wanted = w.targetId ? targets.find((t) => Number(t.id) === Number(w.targetId)) : null;
        // One target only (my business profile) or a preselected one: skip the picker.
        if (wanted) this.chooseTarget(wanted.id, { keep });
        else if (['card', 'link'].includes(kind)) {
            this.prefill();
            if (!keep) w.step = 1;
        } else if (targets.length === 1 && !targets[0].already_promoted) this.chooseTarget(targets[0].id, { keep });
        else w.targetId = null;
        this.renderWizard();
    }

    chooseTarget(id, { keep = false } = {}) {
        const w = this.wizard;
        const target = this.targetsOf(w.kind).find((t) => Number(t.id) === Number(id));
        if (!target) return;
        if (target.already_promoted) {
            toast('This is already being promoted.', { type: 'error' });
            return;
        }
        w.targetId = Number(target.id);
        w.target = target;
        this.prefill();
        if (!keep) w.step = 1;
        this.renderWizard();
    }

    /** The card starts as what the target looks like; the person may edit it. */
    prefill() {
        const w = this.wizard;
        const meta = KIND_META[w.kind];
        if (!w.cta) w.cta = meta?.cta ?? 'Learn more';
        if (w.target) {
            if (!w.title) w.title = w.target.title ?? '';
            if (!w.body) w.body = w.target.subtitle ?? '';
            w.imageUrl = w.imageUrl ?? w.target.image ?? null;
        }
    }

    setCoins(coins) {
        if (!this.wizard) return;
        this.wizard.coins = Math.max(0, Math.trunc(Number(coins) || 0));
        const input = this.root.querySelector('[data-wizard-field="coins"]');
        if (input) input.value = String(this.wizard.coins);
        this.renderQuote();
    }

    quote() {
        const w = this.wizard;
        const rate = this.data?.rates?.[w.kind] ?? 100;
        return { rate, views: quoteViews(w.coins, rate), min: this.data?.min ?? 1, max: this.data?.max ?? 1000000 };
    }

    /** What is wrong with the current step, or null when the person may go on. */
    problem() {
        const w = this.wizard;
        const step = STEPS[w.step];
        if (step === 'target') {
            if (!w.kind) return 'Choose what to promote.';
            if (!['card', 'link'].includes(w.kind) && !w.targetId) return 'Choose one to promote.';
        }
        if (step === 'card') {
            if (['card', 'link'].includes(w.kind) && !/^https?:\/\/\S+/i.test(w.url.trim())) return 'Enter a full web address that starts with http:// or https://.';
            if (w.kind === 'card' && !w.title.trim()) return 'Give your card a headline.';
        }
        if (step === 'budget') {
            const q = this.quote();
            if (w.coins < q.min || w.coins > q.max) return `Choose a budget between ${number(q.min)} and ${number(q.max)} coins.`;
        }
        if (step === 'review' && w.kind === 'status' && !w.agreed) return 'Tick the box to confirm.';
        return null;
    }

    insufficient() {
        return this.wizard.coins > Number(this.data?.balance ?? 0);
    }

    step(delta) {
        const w = this.wizard;
        if (delta > 0) {
            const problem = this.problem();
            if (problem) {
                toast(problem, { type: 'error' });
                return;
            }
            if (STEPS[w.step] === 'budget' && this.insufficient()) return;
        }
        w.step = Math.max(0, Math.min(STEPS.length - 1, w.step + delta));
        this.renderWizard();
    }

    cardPreview() {
        const w = this.wizard;
        return {
            id: '',
            title: w.title.trim() || w.target?.title || 'Your headline',
            body: w.body.trim(),
            cta: w.cta.trim() || KIND_META[w.kind]?.cta || 'Learn more',
            image: w.imageUrl,
            sponsor: this.paid?.user?.name ?? '',
            promoted: true,
            internal: ['status', 'channel', 'community', 'business'].includes(w.kind),
            format: 'row',
        };
    }

    renderWizard() {
        const w = this.wizard;
        if (!w) return;
        const step = STEPS[w.step];
        const stepper = STEPS.map((name, i) => html`<span class="promote-step ${i < w.step ? 'is-done' : ''} ${i === w.step ? 'is-current' : ''}" aria-current="${i === w.step ? 'step' : 'false'}">${i + 1}</span>`).join('');

        this.root.innerHTML = html`
            <form class="wa-group promote-wizard" data-promote-wizard data-step="${step}" data-promote-submit-form novalidate>
                <div class="promote-wizard-head">
                    <button type="button" class="btn-icon" data-wizard-cancel aria-label="Cancel">${raw(icon('x'))}</button>
                    <span class="promote-stepper" aria-label="Step ${w.step + 1} of ${STEPS.length}">${raw(stepper)}</span>
                    <span class="promote-wizard-title">${['What to promote', 'The card', 'Budget', 'Review'][w.step]}</span>
                </div>
                <div class="promote-wizard-body" data-wizard-body>${raw(this[`step_${step}`]())}</div>
                <div class="wa-form-actions promote-wizard-actions" data-wizard-actions>${raw(this.actionsHtml())}</div>
            </form>
        `;
    }

    actionsHtml() {
        const w = this.wizard;
        const step = STEPS[w.step];
        const prev = w.step > 0 ? html`<button type="button" class="btn btn-secondary" data-wizard-prev>${raw(icon('chevron-left'))} Back</button>` : '';
        if (step === 'budget' && this.insufficient()) {
            return html`${raw(prev)}<a class="btn btn-primary" href="${this.walletUrl()}" data-wizard-get-coins>${raw(icon('coins'))} Get coins</a>`;
        }
        if (step === 'review') {
            const disabled = this.submitting || Boolean(this.problem());
            return html`${raw(prev)}<button type="submit" class="btn btn-primary" data-wizard-submit ${raw(disabled ? 'disabled' : '')}>${raw(icon(this.submitting ? 'loader-circle' : 'rocket'))} ${this.submitting ? 'Submitting…' : `Promote for ${number(w.coins)} coins`}</button>`;
        }
        return html`${raw(prev)}<button type="button" class="btn btn-primary" data-wizard-next>Next ${raw(icon('chevron-right'))}</button>`;
    }

    renderReviewButton() {
        const actions = this.root.querySelector('[data-wizard-actions]');
        if (actions) actions.innerHTML = this.actionsHtml();
    }

    step_target() {
        const w = this.wizard;
        const kinds = Object.entries(KIND_META).filter(([kind]) => ['card', 'link'].includes(kind) || this.targetsOf(kind).length);
        const targets = w.kind && !['card', 'link'].includes(w.kind) ? this.targetsOf(w.kind) : [];
        return html`
            <div class="promote-kinds">
                ${raw(kinds.map(([kind, meta]) => html`
                    <button type="button" class="promote-kind ${w.kind === kind ? 'is-selected' : ''}" data-wizard-kind="${kind}" aria-pressed="${w.kind === kind ? 'true' : 'false'}">
                        <span class="promote-kind-icon">${raw(icon(meta.icon))}</span>
                        <span class="promote-kind-label">${meta.label}</span>
                        <span class="promote-kind-text">${meta.text} · ${number(this.data?.rates?.[kind] ?? 100)} coins / 1,000 views</span>
                    </button>`).join(''))}
            </div>
            ${raw(targets.length ? html`
                <h3 class="wa-group-title">Choose one</h3>
                ${raw(targets.map((t) => html`
                    <button type="button" class="wa-row is-link promote-target ${Number(w.targetId) === Number(t.id) ? 'is-selected' : ''}" data-wizard-target="${t.id}" ${raw(t.already_promoted ? 'aria-disabled="true"' : '')}>
                        ${raw(t.image ? html`<span class="promote-thumb" style="background-image:url('${t.image}')"></span>` : html`<span class="promote-thumb is-empty ${t.background ? `is-bg-${t.background}` : ''}">${raw(icon(KIND_META[t.kind]?.icon ?? 'megaphone'))}</span>`)}
                        <span class="wa-row-body">
                            <span class="wa-row-title">${t.title}${raw(t.already_promoted ? html` <span class="promote-chip is-active">Already promoted</span>` : '')}</span>
                            ${raw(t.subtitle ? html`<span class="wa-row-text">${t.subtitle}</span>` : '')}
                        </span>
                        ${raw(icon(Number(w.targetId) === Number(t.id) ? 'circle-check' : 'chevron-right', 'wa-row-chevron'))}
                    </button>`).join(''))}` : '')}
        `;
    }

    step_card() {
        const w = this.wizard;
        const external = ['card', 'link'].includes(w.kind);
        return html`
            <div class="promote-preview" data-wizard-preview>${raw(adCardHtml(this.cardPreview()))}</div>
            <div class="wa-form">
                ${raw(external ? html`
                    <label class="form-group"><span class="form-label">Link</span><input type="url" class="form-control" data-wizard-field="url" value="${w.url}" placeholder="https://your-site.com/offer" maxlength="600" inputmode="url"></label>` : '')}
                <label class="form-group"><span class="form-label">Headline</span><input class="form-control" data-wizard-field="title" value="${w.title}" placeholder="${w.kind === 'link' ? 'Taken from the page when empty' : 'Your headline'}" maxlength="80"></label>
                <label class="form-group"><span class="form-label">Text <span class="optional">(optional)</span></span><textarea class="form-control" data-wizard-field="body" rows="2" maxlength="200">${w.body}</textarea></label>
                <label class="form-group"><span class="form-label">Button</span><input class="form-control" data-wizard-field="cta" value="${w.cta}" maxlength="24"></label>
                ${raw(external ? html`
                    <label class="form-group"><span class="form-label">Picture <span class="optional">(optional, PNG / JPG / WebP up to 3 MB)</span></span><input type="file" class="form-control" accept="image/png,image/jpeg,image/webp" data-wizard-image></label>` : '')}
            </div>
        `;
    }

    step_budget() {
        const w = this.wizard;
        const q = this.quote();
        return html`
            <div class="promote-presets" role="group" aria-label="Budget">
                ${raw(PRESETS.map((coins) => html`<button type="button" class="chip ${w.coins === coins ? 'is-active' : ''}" data-wizard-preset="${coins}">${number(coins)}</button>`).join(''))}
            </div>
            <div class="wa-form">
                <label class="form-group"><span class="form-label">Coins</span><input type="number" class="form-control" data-wizard-field="coins" value="${w.coins}" min="${q.min}" max="${q.max}" step="1" inputmode="numeric"></label>
            </div>
            <p class="promote-quote" data-wizard-quote aria-live="polite">${raw(this.quoteHtml())}</p>
            <p class="wa-group-note">Balance: <strong data-promote-balance>${number(this.data?.balance)}</strong> coins. Views are counted when the card is shown; if you stop early, the coins for the views you did not get come back (rounded in the app's favour by at most one coin).</p>
        `;
    }

    quoteHtml() {
        const w = this.wizard;
        const q = this.quote();
        const short = this.insufficient() ? html` <span class="promote-quote-short">You need ${number(w.coins - Number(this.data?.balance ?? 0))} more coins.</span>` : '';
        return html`≈ <strong data-wizard-views>${number(q.views)}</strong> views · ${number(q.rate)} coins per 1,000 views${raw(short)}`;
    }

    renderQuote() {
        const quote = this.root.querySelector('[data-wizard-quote]');
        if (quote) quote.innerHTML = this.quoteHtml();
        for (const chip of this.root.querySelectorAll('[data-wizard-preset]')) {
            chip.classList.toggle('is-active', Number(chip.dataset.wizardPreset) === this.wizard.coins);
        }
        const actions = this.root.querySelector('[data-wizard-actions]');
        if (actions) actions.innerHTML = this.actionsHtml();
    }

    renderPreview() {
        const preview = this.root.querySelector('[data-wizard-preview]');
        if (preview) preview.innerHTML = adCardHtml(this.cardPreview());
    }

    step_review() {
        const w = this.wizard;
        const q = this.quote();
        const placements = (this.data?.placements?.[w.kind] ?? []).map((p) => ({ chat_list: 'Chats', status_list: 'Status', channels: 'Channels', calls: 'Calls', chat_top: 'Inside chats' })[p] ?? p);
        return html`
            <div class="promote-preview">${raw(adCardHtml(this.cardPreview()))}</div>
            <div class="wa-row"><span class="wa-row-body"><span class="wa-row-title">${KIND_META[w.kind]?.label}</span><span class="wa-row-text">${w.target?.title ?? w.url}</span></span></div>
            <div class="wa-row"><span class="wa-row-body"><span class="wa-row-title">${number(w.coins)} coins ≈ ${number(q.views)} views</span><span class="wa-row-text">Shown in: ${placements.join(', ') || 'no screen is switched on yet'}</span></span></div>
            <p class="wa-group-note">${this.data?.auto_approve && !['card', 'link'].includes(w.kind) ? 'It starts right away.' : 'It starts once an admin has approved it — usually within a day. The coins are on hold until then and come back in full if it is not approved.'}</p>
            ${raw(w.kind === 'status' ? html`
                <label class="wa-row promote-agree"><input type="checkbox" data-wizard-agree ${raw(w.agreed ? 'checked' : '')}><span class="wa-row-body"><span class="wa-row-text">I understand that a promoted status update is shown to people outside my contacts while the promotion runs.</span></span></label>` : '')}
        `;
    }

    async submit() {
        const w = this.wizard;
        if (!w || this.submitting || STEPS[w.step] !== 'review' || this.problem()) return;
        const route = this.routes.promotionsStore;
        if (!route) return;

        this.submitting = true;
        this.renderReviewButton();

        const form = new FormData();
        form.append('kind', w.kind);
        if (w.targetId) form.append('target_id', String(w.targetId));
        form.append('coins', String(w.coins));
        form.append('title', w.title.trim());
        form.append('body', w.body.trim());
        form.append('cta_label', w.cta.trim());
        if (['card', 'link'].includes(w.kind)) form.append('url', w.url.trim());
        if (w.image) form.append('image', w.image);
        form.append('client_token', w.token);

        try {
            const { data } = await axios.post(route, form, { headers: { 'Content-Type': 'multipart/form-data' } });
            this.clearDraft();
            if (this.data) {
                this.data.balance = data.balance;
                this.data.promotions = [data.promotion, ...this.data.promotions.filter((row) => Number(row.id) !== Number(data.promotion.id))];
                for (const t of this.data.targets) {
                    if (t.kind === w.kind && Number(t.id) === Number(w.targetId)) t.already_promoted = true;
                }
            }
            toast(data.promotion.status === 'active' ? 'Your promotion is running.' : 'Submitted — we will let you know once it is approved.', { type: 'success' });
            this.submitting = false;
            this.showList();
        } catch (error) {
            this.submitting = false;
            const payload = error?.response?.data;
            if (error?.response?.status === 422 && payload?.code === 'insufficient_coins') {
                if (this.data) this.data.balance = payload.balance ?? this.data.balance;
                this.saveDraft();
                w.step = STEPS.indexOf('budget');
                toast('Not enough coins. Get coins and come back — your promotion is saved.', { type: 'error' });
                this.renderWizard();
                return;
            }
            toast(payload?.message ?? errorMessage(error, "Couldn't submit the promotion."), { type: 'error' });
            this.renderReviewButton();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Draft (kept while the person goes to buy coins)                     */
    /* ------------------------------------------------------------------ */

    saveDraft() {
        const w = this.wizard;
        if (!w) return;
        try {
            const { image, imageUrl, target, ...rest } = w;
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ ...rest, step: STEPS.indexOf('budget') }));
        } catch {
            /* private mode */
        }
    }

    readDraft() {
        try {
            const raw = sessionStorage.getItem(DRAFT_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    clearDraft() {
        try {
            sessionStorage.removeItem(DRAFT_KEY);
        } catch {
            /* ignore */
        }
    }

    walletUrl() {
        const base = this.routes.settings || window.location.pathname;
        return `${base}${base.includes('?') ? '&' : '?'}tab=wallet`;
    }
}

export function initPromote(config = {}) {
    const root = document.querySelector('[data-promote]');
    if (!root) return null;
    const merged = { ...config, paid: { ...(config.paid ?? {}), user: config.user ?? config.paid?.user ?? null } };
    return new Promote(root, merged);
}
