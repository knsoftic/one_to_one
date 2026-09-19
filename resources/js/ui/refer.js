import axios from '../bootstrap';
import { errorMessage, html, raw } from '../lib/dom';
import { icon } from '../lib/icons';
import { toast } from '../lib/toast';
import { inviteMessage } from '../chat/invite';

/**
 * Settings › Refer & earn (Y2): the person's code and link, Share (the phone's share sheet when
 * there is one, else copy), how much a friend earns them, and who they invited so far.
 */

const shortDate = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
const STATUS = { pending: 'Pending', rewarded: 'Rewarded', void: 'Not counted' };

export class Refer {
    constructor(root, config) {
        this.root = root;
        this.config = config ?? {};
        this.routes = this.config.routes ?? {};
        this.data = null;
        this.loading = false;

        root.addEventListener('click', (event) => this.onClick(event));
    }

    async load({ silent = false } = {}) {
        if (this.loading) return;
        this.loading = true;
        if (!this.data && !silent) this.root.innerHTML = '<div class="wa-loading"><span class="spinner"></span> Loading…</div>';

        try {
            const { data } = await axios.get(this.routes.referral ?? this.root.dataset.route);
            this.data = data;
            this.render();
        } catch (error) {
            this.root.innerHTML = html`<div class="wallet-error">${raw(icon('circle-alert'))}<p>${errorMessage(error, "Couldn't load your invite link.")}</p><button type="button" class="btn btn-secondary btn-sm" data-refer-retry>Try again</button></div>`;
        } finally {
            this.loading = false;
        }
    }

    render() {
        const { code, link, reward, welcome, invited, rewarded, coins_earned: earned, list = [] } = this.data;
        const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

        this.root.innerHTML = html`
            <div class="refer-hero">
                ${raw(icon('gift'))}
                <h3 class="refer-hero-title">Invite friends, earn coins</h3>
                <p class="refer-hero-text">You earn <strong>${Number(reward).toLocaleString()} coins</strong> when a friend joins with your link and verifies their number.${welcome > 0 ? ` They get ${Number(welcome).toLocaleString()} welcome coins too.` : ''}</p>
                <div class="refer-code" data-refer-code>${code}</div>
            </div>
            <div class="refer-link"><code data-refer-link>${link}</code><button type="button" class="btn-icon btn-icon-sm" data-refer-copy="link" aria-label="Copy link">${raw(icon('copy'))}</button></div>
            <div class="refer-actions">
                <button type="button" class="btn btn-primary" data-refer-share>${raw(icon(canShare ? 'share-2' : 'copy'))} ${canShare ? 'Share' : 'Copy link'}</button>
                <button type="button" class="btn btn-secondary" data-refer-copy="code">${raw(icon('copy'))} Copy code</button>
            </div>
            <div class="refer-stats">
                <div class="refer-stat"><span class="refer-stat-value" data-refer-invited>${Number(invited).toLocaleString()}</span><span class="refer-stat-label">Invited</span></div>
                <div class="refer-stat"><span class="refer-stat-value" data-refer-rewarded>${Number(rewarded).toLocaleString()}</span><span class="refer-stat-label">Verified</span></div>
                <div class="refer-stat"><span class="refer-stat-value" data-refer-earned>${Number(earned).toLocaleString()}</span><span class="refer-stat-label">Coins earned</span></div>
            </div>
            <div class="wa-group">
                <h3 class="wa-group-title">People you invited</h3>
                ${list.length
                    ? raw(list.map((row) => html`
                        <div class="wa-row refer-row" data-refer-row="${row.id}">
                            <span class="wa-row-body">
                                <span class="wa-row-title">${row.name}</span>
                                <span class="wa-row-text">${row.date ? shortDate.format(new Date(row.date)) : ''}${row.status === 'rewarded' && row.coins ? ` · +${Number(row.coins).toLocaleString()} coins` : ''}</span>
                            </span>
                            <span class="refer-status is-${row.status}">${STATUS[row.status] ?? row.status}</span>
                        </div>`).join(''))
                    : raw('<p class="wallet-empty">Nobody yet — share your link to get started.</p>')}
                <p class="wa-group-note">Coins are added once your friend verifies their mobile number. Invites from the same phone or connection are limited.</p>
            </div>`;
    }

    onClick(event) {
        const t = event.target;
        if (t.closest('[data-refer-retry]')) return this.load();
        if (t.closest('[data-refer-share]')) return this.share();
        const copy = t.closest('[data-refer-copy]');
        if (copy) return this.copy(copy.dataset.referCopy === 'code' ? this.data.code : this.data.link, copy.dataset.referCopy === 'code' ? 'Code copied.' : 'Link copied.');
        return undefined;
    }

    message() {
        return inviteMessage({ appName: this.config.app?.name ?? this.config.appName ?? 'One2One', url: this.data.link, username: this.config.user?.username });
    }

    async share() {
        if (typeof navigator !== 'undefined' && typeof navigator.share === 'function') {
            try {
                await navigator.share({ title: 'Join me', text: this.message(), url: this.data.link });
                return;
            } catch (error) {
                if (error?.name === 'AbortError') return;
            }
        }
        await this.copy(this.message(), 'Invite copied — paste it anywhere.');
    }

    async copy(text, done) {
        try {
            await navigator.clipboard.writeText(text);
            toast(done, { type: 'success', timeout: 2000 });
        } catch {
            toast("Couldn't copy. Long-press the link to copy it.", { type: 'error' });
        }
    }
}

export function initRefer(config, root = document.querySelector('[data-refer]')) {
    if (!root) return null;
    const refer = new Refer(root, config);
    const section = root.closest('[data-settings-section]');
    const start = () => {
        if (refer.data) refer.load({ silent: true });
        else if (!refer.loading) refer.load();
    };

    if (!section || section.classList.contains('is-active')) start();
    document.addEventListener('settings:section', (event) => {
        if (event.detail?.name === section?.dataset.settingsSection) start();
    });
    return refer;
}
